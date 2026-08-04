<?php

namespace App\Console\Commands;

use App\Models\HeldPush;
use App\Models\Reminder;
use App\Models\ReminderDispatch;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Support\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * The delivery engine: every minute, find reminders whose moment has arrived
 * and push them — exactly once per occurrence.
 *
 * Modelled on LittlePocketMeseum's SendWishlistReminders (query → decide →
 * Notification::send), with the send-once machinery this app needs.
 *
 * QUIET HOURS sit *inside* the send step and nowhere else. Claiming,
 * stale-suppression and advancing are untouched by them: an occurrence that
 * falls in somebody's night is still claimed, still marked sent, and a
 * recurring series still steps forward on schedule. All that changes is which
 * channels reach that one recipient when — the in-app record goes out now, the
 * push is written to `held_pushes` and released at the end of their window by
 * {@see releaseHeldPushes()}, which runs from this same per-minute sweep.
 *
 * Per recipient, deliberately. Two household members share one reminder but
 * not one bedtime, so the same occurrence can be loud for one of them and held
 * for the other — while remaining a single claim and a single dispatch row.
 */
class SendDueReminders extends Command
{
    protected $signature = 'reminders:send-due';

    protected $description = 'Push every reminder whose moment has arrived, exactly once per occurrence.';

    /**
     * How stale an occurrence may be and still be worth pushing, in minutes.
     *
     * Anything older is recorded as dispatched but never sent. Without this,
     * coming back from an hour of downtime — or creating a reminder with a
     * due date already in the past — would fire a wall of pushes for moments
     * the user can no longer act on. Those still surface in Overdue on the
     * Today view, which is the right place for them.
     *
     * Note what this does *not* apply to: a held push. Holding is a decision
     * this command made on purpose, and a push held overnight is meant to be
     * hours old when it lands — running it back through the stale check would
     * mean quiet hours simply swallowed every notification they touched.
     */
    public const STALE_AFTER_MINUTES = 10;

    public function handle(): int
    {
        $now = CarbonImmutable::instance(Carbon::now())->utc();
        $staleBefore = $now->subMinutes(self::STALE_AFTER_MINUTES);

        // Last night's held pushes before this minute's fresh ones: a phone
        // that lights up at 07:00 should show them in the order they happened.
        $released = $this->releaseHeldPushes($now);

        $sent = 0;
        $suppressed = 0;
        $held = 0;
        $advanced = 0;

        foreach (Reminder::query()->due($now)->with(['user', 'list'])->get() as $reminder) {
            $occurredAt = $reminder->effectiveDueAt();

            // Insert first, send second. Row locks are no-ops in SQLite
            // (ARCHITECTURE.md §5), so the unique index on
            // ['reminder_id', 'due_at'] is the whole guarantee: whichever
            // overlapping run wins the insert is the one that notifies. A
            // crash between the two lines loses a push; the reverse — two
            // runs both deciding to send — must never happen.
            $dispatch = $this->claim($reminder, $occurredAt);

            if (! $dispatch instanceof ReminderDispatch) {
                continue;
            }

            if ($occurredAt < $staleBefore) {
                // The occurrence is burnt either way: recording it without
                // sending is what stops the backlog from ever firing. The
                // null `sent_at` is the record of that decision.
                $suppressed++;
            } else {
                $held += $this->notify($reminder, $occurredAt, $now);

                // `sent_at` still means "this occurrence was delivered", and
                // it still was: every recipient got their in-app record at
                // this moment. Quiet hours delay a channel, they do not
                // suppress an occurrence — null here goes on meaning exactly
                // one thing, a stale claim that was never sent at all.
                $dispatch->forceFill(['sent_at' => $now])->save();

                $sent++;
            }

            // The claim is what makes this occurrence ours, so the series
            // moves on here — whether or not a push actually went out. A
            // stale-suppressed occurrence is still spent, and leaving
            // `due_at` on it would park the reminder in Overdue forever.
            // Advancing hangs off the claim, never off the send.
            //
            // The calculator is the *owner's*: there is no acting user in the
            // scheduler, and "every day at 9:00" means nine o'clock where the
            // person who set it up lives.
            if ($reminder->advanceOrComplete(RecurrenceCalculator::for($reminder->user), $occurredAt)) {
                $advanced++;
            }
        }

        $this->info(
            "Reminders due: sent {$sent}, suppressed {$suppressed} as stale, advanced {$advanced} recurring, ".
            "held {$held} push(es) for quiet hours, released {$released}."
        );

        return self::SUCCESS;
    }

    /**
     * Notify everyone this occurrence belongs to, splitting them by whose
     * quiet hours are in force *right now*.
     *
     * "Right now" rather than "at the occurrence" is the deliberate reading:
     * the question quiet hours answer is "may this phone buzz at this moment",
     * and the moment is the send, not the due time it is catching up on (they
     * differ by at most the stale window).
     *
     * Returns how many pushes were newly held.
     */
    private function notify(Reminder $reminder, CarbonImmutable $occurredAt, CarbonImmutable $now): int
    {
        /** @var list<User> $loud */
        $loud = [];
        /** @var list<User> $quiet */
        $quiet = [];
        /** @var list<CarbonImmutable> $releases */
        $releases = [];

        foreach ($reminder->recipients() as $recipient) {
            $window = $recipient->quietHours();

            if (! $window->covers($now)) {
                $loud[] = $recipient;

                continue;
            }

            $quiet[] = $recipient;
            $releases[] = $window->endsAfter($now);
        }

        if ($loud !== []) {
            Notification::send($loud, new ReminderDueNotification($reminder, $occurredAt));
        }

        if ($quiet === []) {
            return 0;
        }

        // The in-app half, now — so the reminder is in their history and on
        // their badge at the moment it was actually due.
        Notification::send($quiet, new ReminderDueNotification(
            $reminder,
            $occurredAt,
            ReminderDueNotification::CHANNELS_IN_APP,
        ));

        $held = 0;

        foreach ($quiet as $index => $recipient) {
            if ($this->hold($recipient, $reminder, $occurredAt, $releases[$index])) {
                $held++;
            }
        }

        return $held;
    }

    /**
     * Park this recipient's push until their window ends.
     *
     * Returns false when a row for the same (recipient, reminder, occurrence)
     * already exists — the unique index doing the same job it does for the
     * dispatch log, so two overlapping sweeps cannot hold the same push twice.
     */
    private function hold(
        User $user,
        Reminder $reminder,
        CarbonImmutable $occurredAt,
        CarbonImmutable $releaseAt,
    ): bool {
        try {
            HeldPush::query()->create([
                'user_id' => $user->id,
                'reminder_id' => $reminder->id,
                'occurred_at' => $occurredAt,
                'release_at' => $releaseAt,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Let out every push whose quiet window has ended.
     *
     * Delete-then-send, which is claim-then-send wearing the other hat: the
     * row *is* the claim, so whichever run manages to remove it is the one
     * that buzzes. Losing that race means somebody else already did the work.
     *
     * Only `WebPushChannel` — the database row for this occurrence was written
     * when the reminder came due, and a second one would show the same send
     * twice in the history feed.
     */
    private function releaseHeldPushes(CarbonImmutable $now): int
    {
        $released = 0;

        foreach (HeldPush::query()->releasable($now)->with(['user', 'reminder'])->get() as $held) {
            if (HeldPush::query()->whereKey($held->getKey())->delete() === 0) {
                continue;
            }

            $user = $held->user;

            if (! $user instanceof User || $held->isSuperseded()) {
                continue;
            }

            /** @var Reminder $reminder guarded by isSuperseded() */
            $reminder = $held->reminder;

            Notification::send([$user], new ReminderDueNotification(
                $reminder,
                CarbonImmutable::instance($held->occurred_at)->utc(),
                ReminderDueNotification::CHANNELS_PUSH,
            ));

            $released++;
        }

        return $released;
    }

    /**
     * Try to claim this occurrence by writing its dispatch row.
     *
     * Returns null when another run (or an earlier tick) already holds it —
     * the unique-constraint violation *is* the "already dispatched" check, so
     * no read-then-write race can slip between them.
     */
    private function claim(Reminder $reminder, CarbonImmutable $occurredAt): ?ReminderDispatch
    {
        try {
            return ReminderDispatch::query()->create([
                'reminder_id' => $reminder->id,
                'due_at' => $occurredAt,
                'sent_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
