<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A web push that came due inside its recipient's quiet hours, waiting for
 * the window to end.
 *
 * Only the *push* waits. The in-app notification for the same occurrence was
 * written the moment the reminder came due, so history, the unread badge and
 * the Today view are all correct immediately — this row exists purely to buzz
 * the phone later (settings-and-quiet-hours spec: "the Today view still shows
 * them immediately; only the push is held").
 *
 * Per recipient, unlike {@see ReminderDispatch}: two household members can
 * keep different hours, so one shared occurrence can be loud for one of them
 * and held for the other.
 *
 * @property int $id
 * @property int $user_id
 * @property int $reminder_id
 * @property int|null $reminder_alert_id
 * @property Carbon $occurred_at
 * @property Carbon $release_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Reminder|null $reminder
 * @property-read ReminderAlert|null $alert
 */
#[Fillable(['user_id', 'reminder_id', 'reminder_alert_id', 'occurred_at', 'release_at'])]
class HeldPush extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // UTC, like every stored datetime.
            'occurred_at' => 'datetime',
            'release_at' => 'datetime',
        ];
    }

    /**
     * Who is waiting to be buzzed.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What they are waiting to be buzzed about.
     *
     * @return BelongsTo<Reminder, $this>
     */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }

    /**
     * The pre-alert this push is about, when it is one — null for an ordinary
     * "your reminder is due" push, which is every row written before
     * pre-alerts shipped.
     *
     * There is **no foreign key** behind this column (SQLite cannot add one
     * to an existing table — ARCHITECTURE.md §5), so a dangling id is
     * possible in principle and {@see isSuperseded()} treats it as a reason to
     * drop the push rather than a reason to crash.
     *
     * @return BelongsTo<ReminderAlert, $this>
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(ReminderAlert::class, 'reminder_alert_id');
    }

    /**
     * Whether this held push is a pre-alert rather than a reminder coming due.
     */
    public function isPreAlert(): bool
    {
        return $this->reminder_alert_id !== null;
    }

    /**
     * Whether this held push has been overtaken by events and should be
     * dropped rather than delivered when its window ends.
     *
     * Two ways that happens, and one non-way that matters more:
     *
     * - The reminder is **gone** (deleted out from under a database whose FK
     *   enforcement may be off) — nothing to buzz about.
     * - The reminder is **completed** — it was dealt with during the night.
     * - The occurrence was **snoozed forward**. A snooze mints a fresh
     *   occurrence that will push on its own, so releasing the old one too
     *   would buzz twice for the same thing.
     *
     * The non-way: a *recurring* reminder's `due_at` only moves when the user
     * completes it (recurrence close-out), never while a push is merely held,
     * so "due_at has moved on" is not one of the checks here at all —
     * `snoozed_until` is the only column this reads, which is exactly why a
     * snooze (not a completion) is what supersedes a held push.
     */
    public function isSuperseded(): bool
    {
        if ($this->isPreAlert()) {
            return $this->isPreAlertSuperseded();
        }

        $reminder = $this->reminder;

        if (! $reminder instanceof Reminder) {
            return true;
        }

        if ($reminder->completed_at !== null) {
            return true;
        }

        $snoozed = $reminder->snoozed_until;

        return $snoozed !== null
            && CarbonImmutable::instance($snoozed)->utc()
                ->greaterThan(CarbonImmutable::instance($this->occurred_at)->utc());
    }

    /**
     * The pre-alert half of {@see isSuperseded()}.
     *
     * Four ways a held pre-alert stops being worth buzzing about:
     *
     * - The **alert row is gone** — unfiled from the reminder overnight, or
     *   taken with a deleted reminder. Nothing behind it to alert about.
     * - The **reminder is completed** — dealt with during the night, so a
     *   nudge that it is coming is noise.
     * - The alert's current `effectiveFireAt()` **no longer equals** the
     *   moment held. Snoozing the alert, or editing the reminder's due time,
     *   mints a fresh fire moment that will push on its own; releasing this
     *   one too would buzz twice.
     * - The fire moment is **no longer strictly before** the reminder's
     *   effective due moment. Pushing `due_at` earlier (or snoozing the alert
     *   past it) makes the pre-alert redundant — the main notification is
     *   coming, and it is the one that matters.
     */
    private function isPreAlertSuperseded(): bool
    {
        $alert = $this->alert;

        if (! $alert instanceof ReminderAlert) {
            return true;
        }

        $reminder = $alert->reminder;

        if (! $reminder instanceof Reminder || $reminder->completed_at !== null) {
            return true;
        }

        $fireAt = $alert->effectiveFireAt();

        if (! $fireAt->equalTo(CarbonImmutable::instance($this->occurred_at)->utc())) {
            return true;
        }

        return ! $fireAt->lessThan($reminder->effectiveDueAt());
    }

    /**
     * Holds whose window has ended — the drain queue, oldest occurrence
     * first so a backlog arrives in the order it happened.
     *
     * @param  Builder<HeldPush>  $query
     */
    #[Scope]
    protected function releasable(Builder $query, ?DateTimeInterface $at = null): void
    {
        $at = Carbon::instance($at ?? Carbon::now())->utc();

        $query->where('release_at', '<=', $at->format('Y-m-d H:i:s'))
            ->orderBy('occurred_at');
    }
}
