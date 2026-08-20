<?php

namespace App\Console\Commands;

use App\Models\HeldPush;
use App\Models\Reminder;
use App\Models\ReminderAlert;
use App\Models\ReminderAlertDispatch;
use App\Models\ReminderDispatch;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Notifications\ReminderPreAlertNotification;
use App\Support\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
 * A recurring reminder does not move on by itself here — normally only {@see
 * \App\Models\Reminder::complete()} advances `due_at`. This command claims and
 * sends (or stale-suppresses) an occurrence at most once, and then leaves it
 * alone: an uncompleted recurring reminder sits in Overdue, unchanged,
 * however many ticks pass, exactly like a one-off would. The single exception
 * is a reminder its owner marked `auto_complete`, which advances off the claim
 * — see the comment at the foot of the main loop.
 *
 * QUIET HOURS sit *inside* the send step and nowhere else. Claiming and
 * stale-suppression are untouched by them: an occurrence that falls in
 * somebody's night is still claimed and still marked sent. All that changes is
 * which channels reach that one recipient when — the in-app record goes out
 * now, the push is written to `held_pushes` and released at the end of their
 * window by {@see releaseHeldPushes()}, which runs from this same per-minute
 * sweep.
 *
 * Per recipient, deliberately. Two household members share one reminder but
 * not one bedtime, so the same occurrence can be loud for one of them and held
 * for the other — while remaining a single claim and a single dispatch row.
 *
 * A SECOND PASS follows the main one, for pre-alerts (pre-alerts spec): the
 * same claim-first-send-second machinery against `reminder_alert_dispatches`,
 * the same stale window, the same quiet-hours split. Two rules are its own —
 * an alert is anchored to the reminder's **raw** `due_at`, so a main snooze
 * never moves it, and it fires only while the reminder's effective due moment
 * is still ahead. When that second rule fails the alert is skipped *without*
 * being claimed, so pushing the due date out later leaves the alert free to
 * fire after all.
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

            // Deliberately nothing here moves `due_at` — with exactly one
            // exception, below. Being notified is not being done: a recurring
            // reminder only steps to its next occurrence when the user
            // completes it (Reminder::complete()). Parking an uncompleted
            // occurrence in Overdue forever is the point, not a bug: it stays
            // visible until it's actually dealt with.
            //
            // The exception is the per-reminder `auto_complete` opt-in
            // (auto-complete-on-dispatch spec). For a reminder whose owner
            // ticked it, going off *is* being dealt with, so the series rolls
            // straight on rather than waiting in Overdue.
            //
            // Note where this sits: hung off the **claim**, so a
            // stale-suppressed occurrence advances too. Sitting in Overdue
            // after an outage is precisely what this toggle opts out of, and
            // the catch-up floor in RecurrenceCalculator::nextAfter() lands
            // the series on the next occurrence still ahead of now rather than
            // replaying the backlog one tick at a time.
            //
            // No ReminderCompletion row is written: that log records what a
            // person did, and nobody did anything here — the dispatch row
            // above is the record that this occurrence happened.
            if ($reminder->auto_complete && $reminder->isRecurring()) {
                // The owner's clock, never the scheduler's: a series belongs
                // to whoever set it up, so "every day at 9:00" goes on meaning
                // nine o'clock where they are (RecurrenceCalculator::for()).
                $reminder->advanceOrComplete(
                    RecurrenceCalculator::for($reminder->user),
                    $occurredAt,
                );
            }
        }

        $alerts = 0;
        $alertsSuppressed = 0;

        // The pre-alert pass runs *after* the main one, so that a reminder
        // coming due in this same minute has already had its real
        // notification claimed and sent before anything decides whether a
        // heads-up about it is still worth sending.
        foreach ($this->alertWorkingSet() as $alert) {
            $reminder = $alert->reminder;

            if (! $reminder instanceof Reminder) {
                continue;
            }

            $fireAt = $alert->effectiveFireAt();

            if ($fireAt->greaterThan($now)) {
                continue;
            }

            // Strictly before the main moment, or not at all: once the
            // reminder itself is due, a warning that it is coming is noise,
            // and the real notification is on its way regardless.
            //
            // The test is on **now**, not on `$fireAt`. Given the check above
            // ($fireAt <= now), `now < effectiveDueAt` already implies
            // `$fireAt < effectiveDueAt`, so this is the stronger of the two
            // and the weaker one adds nothing. What the weaker one *misses*
            // is the case this is really here for: a reminder whose fire
            // moment and due moment are both already past — a five-minute
            // alert on something created or edited into the recent past, say.
            // Comparing `$fireAt` alone still calls that "before" and fires a
            // "Due in 5 minutes." push for something that is due now, in the
            // same sweep as the real notification.
            //
            // Skipped **without claiming**, deliberately. Pushing `due_at`
            // out later makes this alert meaningful again, and a claim
            // written now would burn the moment it would want to fire at —
            // permanently, for a snoozed alert, whose fire key is pinned by
            // `snoozed_until` and would never move off the burnt one.
            if (! $now->lessThan($reminder->effectiveDueAt())) {
                continue;
            }

            // Claim first, send second — the same order, and for the same
            // reason, as the main loop above. Never reorder this.
            $dispatch = $this->claimAlert($alert, $fireAt);

            if (! $dispatch instanceof ReminderAlertDispatch) {
                continue;
            }

            if ($fireAt < $staleBefore) {
                $alertsSuppressed++;

                continue;
            }

            $held += $this->notifyAlert($alert, $reminder, $fireAt, $now);

            $dispatch->forceFill(['sent_at' => $now])->save();

            $alerts++;
        }

        $this->info(
            "Reminders due: sent {$sent}, suppressed {$suppressed} as stale, ".
            "pre-alerts sent {$alerts}, suppressed {$alertsSuppressed} as stale, ".
            "held {$held} push(es) for quiet hours, released {$released}."
        );

        return self::SUCCESS;
    }

    /**
     * Every pre-alert that could conceivably want to fire — one query, then
     * the real decision in PHP.
     *
     * The filter that matters is `effectiveFireAt()`, which is
     * `coalesce(alert.snoozed_until, reminder.due_at - offset_minutes)`. Doing
     * that arithmetic in SQL would mean SQLite-specific `datetime(...,
     * '-N minutes')` expressions leaking into a query the rest of the app
     * would then have to keep working; the row count here is personal-scale
     * (alerts on uncompleted reminders, a handful at most), so PHP does it.
     *
     * Completed reminders are excluded in SQL because that genuinely is a
     * plain column test, and it is the one that removes most of the table.
     *
     * @return EloquentCollection<int, ReminderAlert>
     */
    private function alertWorkingSet(): EloquentCollection
    {
        return ReminderAlert::query()
            // Through the `pending()` scope rather than a hand-written
            // `completed_at is null`, so "not finished with" goes on having
            // one definition; a subselect rather than a `whereHas` closure
            // only because it keeps the scope's own type intact.
            ->whereIn('reminder_id', Reminder::query()->pending()->select('id'))
            // `reminder.user` and `reminder.list` are what the notification
            // reads for its recipients and its headline.
            ->with(['reminder.user', 'reminder.list'])
            ->orderBy('reminder_id')
            ->orderBy('offset_minutes')
            ->get();
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
        ['loud' => $loud, 'quiet' => $quiet, 'releases' => $releases] = $this->splitByQuietHours($reminder, $now);

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
     * The pre-alert twin of {@see notify()} — same split, same holding, a
     * different notification.
     *
     * Recipients come from the reminder, not the alert: an alert has no
     * audience of its own, so a shared reminder's heads-up fans out to the
     * household exactly like the reminder itself does.
     *
     * Returns how many pushes were newly held.
     */
    private function notifyAlert(
        ReminderAlert $alert,
        Reminder $reminder,
        CarbonImmutable $fireAt,
        CarbonImmutable $now,
    ): int {
        ['loud' => $loud, 'quiet' => $quiet, 'releases' => $releases] = $this->splitByQuietHours($reminder, $now);

        if ($loud !== []) {
            Notification::send($loud, new ReminderPreAlertNotification($alert, $fireAt));
        }

        if ($quiet === []) {
            return 0;
        }

        Notification::send($quiet, new ReminderPreAlertNotification(
            $alert,
            $fireAt,
            ReminderPreAlertNotification::CHANNELS_IN_APP,
        ));

        $held = 0;

        foreach ($quiet as $index => $recipient) {
            if ($this->hold($recipient, $reminder, $fireAt, $releases[$index], $alert)) {
                $held++;
            }
        }

        return $held;
    }

    /**
     * Sort a reminder's recipients into the ones who may be buzzed right now
     * and the ones whose quiet hours are in force, with the moment each of the
     * latter's window ends.
     *
     * One mechanism, two callers — the due pass and the pre-alert pass ask the
     * same question of the same people, and answering it in one place is what
     * keeps "whose night is it" from drifting between them.
     *
     * "Right now" rather than "at the occurrence" is the deliberate reading:
     * the question is whether this phone may buzz at this moment.
     *
     * @return array{loud: list<User>, quiet: list<User>, releases: list<CarbonImmutable>}
     */
    private function splitByQuietHours(Reminder $reminder, CarbonImmutable $now): array
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

        return ['loud' => $loud, 'quiet' => $quiet, 'releases' => $releases];
    }

    /**
     * Park this recipient's push until their window ends.
     *
     * Returns false when a row for the same (recipient, reminder, occurrence)
     * already exists — the unique index doing the same job it does for the
     * dispatch log, so two overlapping sweeps cannot hold the same push twice.
     *
     * `$alert` is what makes the row a held *pre-alert* rather than a held due
     * notification; the same index covers both, because an alert only ever
     * fires strictly before its reminder's effective due moment and so can
     * never share an `occurred_at` with it.
     */
    private function hold(
        User $user,
        Reminder $reminder,
        CarbonImmutable $occurredAt,
        CarbonImmutable $releaseAt,
        ?ReminderAlert $alert = null,
    ): bool {
        try {
            HeldPush::query()->create([
                'user_id' => $user->id,
                'reminder_id' => $reminder->id,
                'reminder_alert_id' => $alert?->id,
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

        $with = ['user', 'reminder', 'alert.reminder'];

        foreach (HeldPush::query()->releasable($now)->with($with)->get() as $held) {
            if (HeldPush::query()->whereKey($held->getKey())->delete() === 0) {
                continue;
            }

            $user = $held->user;

            if (! $user instanceof User || $held->isSuperseded()) {
                continue;
            }

            $occurredAt = CarbonImmutable::instance($held->occurred_at)->utc();

            if ($held->isPreAlert()) {
                /** @var ReminderAlert $alert guarded by isSuperseded() */
                $alert = $held->alert;

                Notification::send([$user], new ReminderPreAlertNotification(
                    $alert,
                    $occurredAt,
                    ReminderPreAlertNotification::CHANNELS_PUSH,
                ));

                $released++;

                continue;
            }

            /** @var Reminder $reminder guarded by isSuperseded() */
            $reminder = $held->reminder;

            Notification::send([$user], new ReminderDueNotification(
                $reminder,
                $occurredAt,
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

    /**
     * Try to claim this alert moment by writing its dispatch row — the
     * pre-alert twin of {@see claim()}, against its own table.
     *
     * Returns null when another run (or an earlier tick) already holds it.
     */
    private function claimAlert(ReminderAlert $alert, CarbonImmutable $fireAt): ?ReminderAlertDispatch
    {
        try {
            return ReminderAlertDispatch::query()->create([
                'reminder_alert_id' => $alert->id,
                'fire_at' => $fireAt,
                'sent_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
