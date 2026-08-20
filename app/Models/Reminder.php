<?php

namespace App\Models;

use App\Support\RecurrenceCalculator;
use App\Support\RecurrenceRule;
use Carbon\CarbonImmutable;
use Database\Factories\ReminderFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $list_id
 * @property string $title
 * @property string|null $notes
 * @property Carbon $due_at
 * @property bool $is_shared
 * @property string|null $repeat_unit
 * @property int $repeat_interval
 * @property list<int>|null $repeat_weekdays
 * @property Carbon|null $repeat_until
 * @property int|null $repeat_anchor_day
 * @property string|null $repeat_month_mode
 * @property int|null $repeat_week_of_month
 * @property bool $auto_complete
 * @property Carbon|null $completed_at
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read ReminderList|null $list
 */
#[Fillable([
    'user_id', 'list_id', 'title', 'notes', 'due_at', 'is_shared',
    'repeat_unit', 'repeat_interval', 'repeat_weekdays', 'repeat_until', 'repeat_anchor_day',
    'repeat_month_mode', 'repeat_week_of_month', 'auto_complete',
    'completed_at', 'snoozed_until',
])]
class Reminder extends Model
{
    /** @use HasFactory<ReminderFactory> */
    use HasFactory;

    /**
     * The SQL expression behind {@see effectiveDueAt()} — the moment a
     * reminder wants attention, snooze included.
     */
    public const EFFECTIVE_DUE_AT = 'coalesce(snoozed_until, due_at)';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Stored and cast as UTC; only the presentation layer localizes.
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'is_shared' => 'boolean',
            'repeat_interval' => 'integer',
            'repeat_weekdays' => 'array',
            // A local calendar day, not an instant: only ever read through
            // format('Y-m-d'), never shifted into another timezone.
            'repeat_until' => 'date',
            'repeat_anchor_day' => 'integer',
            'repeat_week_of_month' => 'integer',
            // Only ever true alongside a repeat rule — ReminderRequest
            // normalises a one-off back to false on the way in.
            'auto_complete' => 'boolean',
        ];
    }

    /**
     * The user this reminder belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The owner's own list this reminder is filed under, if any.
     *
     * Lists are personal — sharing a reminder does not share the filing
     * around it — so this is always the *owner's* filing specifically. A
     * household member's own filing of a shared reminder they don't own
     * lives in {@see filings()} instead; use {@see listFor()} to read
     * whichever one applies to a given viewer. Nullable by design — deleting
     * a list orphans its reminders rather than taking them with it.
     *
     * @return BelongsTo<ReminderList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(ReminderList::class, 'list_id');
    }

    /**
     * Household members' own filings of this reminder — never the owner's
     * own (that's {@see list()}/`list_id`), always someone it's shared with.
     *
     * @return HasMany<ReminderListFiling, $this>
     */
    public function filings(): HasMany
    {
        return $this->hasMany(ReminderListFiling::class);
    }

    /**
     * Which list this reminder is filed under, from a given viewer's own
     * point of view: the owner reads `list_id` directly, anyone else reads
     * their own row in {@see filings()} — independent of what the owner (or
     * any other household member) filed it under.
     *
     * Uses `firstWhere` rather than `filings->first()` so this stays correct
     * whether `filings` was eager-loaded pre-scoped to the viewer or pulled
     * in unscoped — the latter would otherwise risk handing back a different
     * household member's list.
     */
    public function listIdFor(User $viewer): ?int
    {
        if ($viewer->id === $this->user_id) {
            return $this->list_id;
        }

        return $this->filings->firstWhere('user_id', $viewer->id)?->list_id;
    }

    /**
     * The list model behind {@see listIdFor()}, for presenting the badge.
     */
    public function listFor(User $viewer): ?ReminderList
    {
        if ($viewer->id === $this->user_id) {
            return $this->list;
        }

        return $this->filings->firstWhere('user_id', $viewer->id)?->list;
    }

    /**
     * The log of occurrences already handled by the delivery engine.
     *
     * @return HasMany<ReminderDispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(ReminderDispatch::class);
    }

    /**
     * The pre-alerts that run ahead of this reminder — "also tell me an hour
     * before" (pre-alerts spec).
     *
     * Ordered by horizon so every surface that renders them — the form's
     * chips, a row's tooltip, the presenter — reads in the same order the
     * picker offers them in, without each one having to sort for itself.
     *
     * @return HasMany<ReminderAlert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(ReminderAlert::class)->orderBy('offset_minutes');
    }

    /**
     * Forget every alert snooze on this reminder.
     *
     * An alert's `snoozed_until` belongs to the occurrence it was set on. Left
     * behind, the coalesce in {@see ReminderAlert::effectiveFireAt()} would
     * pin the alert to a moment already in the past forever, and the *next*
     * occurrence's pre-alert would never fire. So it is cleared at the two
     * points an occurrence stops being the current one: when the series
     * actually advances or completes ({@see advanceOrComplete()}), and when
     * the user edits `due_at` (ReminderController@update).
     *
     * Returns how many alerts were cleared.
     */
    public function clearAlertSnoozes(): int
    {
        $cleared = ReminderAlert::query()
            ->where('reminder_id', $this->getKey())
            ->whereNotNull('snoozed_until')
            ->update(['snoozed_until' => null]);

        // Anything already loaded is now stale — a caller re-reading
        // `$reminder->alerts` must not be handed the snoozes just dropped.
        $this->unsetRelation('alerts');

        return $cleared;
    }

    /**
     * Everyone who should be notified when this reminder comes due.
     *
     * This is the delivery engine's single recipient-resolution seam — the
     * send site must never reach for `$reminder->user` itself. A private
     * reminder reaches its owner; a shared one reaches every member of the
     * *owner's* household, which is the same set `visibleTo()` computes.
     *
     * Bookkeeping stays per-occurrence, not per-recipient: the caller claims
     * one dispatch row and `Notification::send()` fans out from there.
     *
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        if (! $this->is_shared) {
            return collect([$this->user]);
        }

        return $this->user->householdMembers();
    }

    /**
     * Whether a user may see this reminder — the row-level twin of the
     * {@see visibleTo()} scope, for the policy and anything holding a model
     * rather than a query. Keep the two in step: they are one rule.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        return $this->is_shared && $user->sharesHouseholdWith($this->user);
    }

    /**
     * When this reminder actually wants attention: a snooze moves the moment
     * forward, so a snoozed reminder is neither due nor overdue until then.
     *
     * The same coalesce the Today view buckets on (TodayBoard) and the same
     * value the dispatch log keys its occurrence by — one definition of "the
     * moment", used by every surface.
     */
    public function effectiveDueAt(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->snoozed_until ?? $this->due_at)->utc();
    }

    /**
     * Whether this reminder repeats. A null unit is a one-off.
     */
    public function isRecurring(): bool
    {
        return $this->repeat_unit !== null;
    }

    /**
     * This reminder's repeat rule, or null when it is a one-off.
     *
     * The columns are the storage; {@see RecurrenceRule} is the shape the
     * calculator and the presenter both work against, so neither has to know
     * how a rule is spelled in the database.
     */
    public function recurrenceRule(): ?RecurrenceRule
    {
        if ($this->repeat_unit === null) {
            return null;
        }

        return new RecurrenceRule(
            unit: $this->repeat_unit,
            interval: max(1, $this->repeat_interval),
            weekdays: $this->repeat_weekdays ?? [],
            until: $this->repeat_until?->format('Y-m-d'),
            anchorDay: $this->repeat_anchor_day,
            monthMode: $this->repeat_month_mode,
            weekOfMonth: $this->repeat_week_of_month,
        );
    }

    /**
     * Move a repeating reminder on to its next occurrence — or complete it
     * when its rule has run out.
     *
     * This is the seam {@see complete()} goes through, so "what happens when
     * an occurrence is dealt with" is defined once. Being pushed is not the
     * same as being done, so a recurring reminder normally never moves until a
     * user actually completes it; the one exception is a reminder whose owner
     * ticked `auto_complete`, for which `SendDueReminders` calls this straight
     * off the claim (auto-complete-on-dispatch spec). The delivery engine
     * touches `due_at` in that one case and nowhere else.
     *
     *   $handled = $reminder->advanceOrComplete($calculator, $occurredAt);
     *
     * Returns **false only for a one-off reminder**, which it leaves entirely
     * alone — the caller decides what that means. The complete endpoint reads
     * false as "nothing repeats here, set completed_at yourself".
     *
     * Two moments are in play and they are not the same one:
     *
     * - `$occurredAt` is the *effective* occurrence just handled
     *   (`coalesce(snoozed_until, due_at)`), which is what the dispatch log
     *   keys on. It only sets the floor the next occurrence has to clear.
     * - `due_at` is where the *series* actually stands, and it is what the
     *   next occurrence is computed from. A 9:00 daily reminder snoozed to
     *   14:00 fires at 14:00 and then comes back tomorrow at 9:00 — the
     *   snooze moves one occurrence, never the schedule.
     *
     * The write is a compare-and-swap on `due_at` (row locks are no-ops in
     * SQLite — ARCHITECTURE.md §5): the row only moves if it is still on the
     * occurrence we read. A concurrent run that got there first has already
     * done this work, so losing the race is success, not failure.
     */
    public function advanceOrComplete(RecurrenceCalculator $calculator, ?DateTimeInterface $occurredAt = null): bool
    {
        $rule = $this->recurrenceRule();

        if ($rule === null) {
            return false;
        }

        // Read once, in UTC, without mutating the model's own Carbon.
        $previous = CarbonImmutable::instance($this->due_at)->utc();
        $handled = CarbonImmutable::instance($occurredAt ?? $this->effectiveDueAt())->utc();

        $next = $calculator->nextAfter($rule, $previous, $handled->max(CarbonImmutable::now()->utc()));

        // A finished series keeps its last due_at and gains a completed_at;
        // an ongoing one steps forward and drops the snooze, which belonged
        // to the occurrence that has just been dealt with.
        $changes = $next === null
            ? ['completed_at' => Carbon::now(), 'snoozed_until' => null]
            : ['due_at' => $next, 'snoozed_until' => null];

        $advanced = static::query()
            ->whereKey($this->getKey())
            ->where('due_at', $previous->format('Y-m-d H:i:s'))
            ->update($changes);

        if ($advanced > 0) {
            $this->forceFill($changes)->syncOriginal();

            // The occurrence just dealt with was the one every alert snooze
            // belonged to. Dropping them here is what lets the next
            // occurrence's pre-alerts fire on schedule — and losing the CAS
            // race means somebody else already did this too.
            $this->clearAlertSnoozes();
        }

        return true;
    }

    /**
     * Tick this reminder off, and hand back the state it was in beforehand.
     *
     * The two halves of "done" go through one door. A repeating reminder is
     * never finished by being handled once, so it steps to its next
     * occurrence through the same seam the delivery engine uses; only a
     * one-off — the case `advanceOrComplete()` declines and leaves untouched
     * — actually gains a `completed_at`.
     *
     * The returned snapshot is what makes Undo possible: it is taken before
     * anything moves, and `restoreState()` puts every one of those columns
     * back, so undoing a completed *recurring* reminder rewinds the
     * occurrence too rather than leaving the series a step ahead.
     *
     * Row-level by design: a shared reminder is one row, so one household
     * member completing it completes it for both (shared-reminders spec).
     *
     * @return array{completed_at: string|null, due_at: string, snoozed_until: string|null}
     */
    public function complete(RecurrenceCalculator $calculator): array
    {
        $prior = $this->currentState();
        $occurredAt = $this->effectiveDueAt();

        if (! $this->advanceOrComplete($calculator)) {
            $this->forceFill(['completed_at' => Carbon::now()])->save();
        }

        ReminderCompletion::query()->create([
            'user_id' => $this->user_id,
            'reminder_id' => $this->id,
            'title' => $this->title,
            'is_shared' => $this->is_shared,
            'occurred_at' => $occurredAt,
            'completed_at' => Carbon::now(),
        ]);

        return $prior;
    }

    /**
     * Push this reminder's moment out to `$until` (UTC).
     *
     * `due_at` is deliberately left alone: the snooze belongs to the
     * occurrence, not to the series, so a daily 9:00 reminder snoozed to
     * 14:00 comes back tomorrow at 9:00 (recurrence close-out).
     *
     * How this lands on the delivery engine: the dispatch log keys on the
     * *effective* occurrence, `coalesce(snoozed_until, due_at)`. Writing a
     * new future `snoozed_until` therefore mints a brand new dispatch key, so
     * an occurrence that has already fired re-fires by itself — nothing has
     * to delete dispatch rows. The one way to lose a snooze would be landing
     * exactly on a moment already in the log, where the claim would swallow
     * it silently; every preset is a future moment and dispatch rows only
     * ever exist for occurrences already past, so it cannot arise. (The
     * custom picker refuses past times for the same reason.)
     */
    public function snoozeUntil(DateTimeInterface $until): void
    {
        $this->forceFill([
            'snoozed_until' => CarbonImmutable::instance($until)->utc(),
        ])->save();
    }

    /**
     * This reminder's undoable state, as ISO-8601 UTC strings.
     *
     * Strings rather than Carbons because this snapshot's whole job is to
     * survive a round trip out to the client (flashed onto the redirect, sent
     * back by the toast's Undo button) and come home unambiguous.
     *
     * @return array{completed_at: string|null, due_at: string, snoozed_until: string|null}
     */
    public function currentState(): array
    {
        return [
            'completed_at' => $this->completed_at === null
                ? null
                : CarbonImmutable::instance($this->completed_at)->utc()->toIso8601String(),
            'due_at' => CarbonImmutable::instance($this->due_at)->utc()->toIso8601String(),
            'snoozed_until' => $this->snoozed_until === null
                ? null
                : CarbonImmutable::instance($this->snoozed_until)->utc()->toIso8601String(),
        ];
    }

    /**
     * Put the three moving parts back exactly as they were — the other half
     * of {@see currentState()}, and everything Undo needs.
     *
     * Note what this does *not* touch: `reminder_dispatches`. Un-completing a
     * recurring reminder rewinds `due_at` onto an occurrence the delivery
     * engine may already have logged, and that is right — that occurrence
     * genuinely did fire once, and the log is what stops it firing twice.
     */
    public function restoreState(
        ?DateTimeInterface $completedAt,
        DateTimeInterface $dueAt,
        ?DateTimeInterface $snoozedUntil,
    ): void {
        $this->forceFill([
            'completed_at' => $completedAt === null ? null : CarbonImmutable::instance($completedAt)->utc(),
            'due_at' => CarbonImmutable::instance($dueAt)->utc(),
            'snoozed_until' => $snoozedUntil === null ? null : CarbonImmutable::instance($snoozedUntil)->utc(),
        ])->save();
    }

    /**
     * The reminders a user is allowed to see: their own, plus the shared
     * reminders of everyone they share a household with.
     *
     * This is THE visibility rule. Every list surface — the reminders index,
     * the Today board, the policy's row-level twin, and any feed that ships
     * later — goes through here rather than filtering on `user_id` itself,
     * so "who can see what" can only ever be changed in one place.
     *
     * @param  Builder<Reminder>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->where('user_id', $user->id);

            if ($user->household_id === null) {
                return;
            }

            // Shared reminders owned by anyone in the same household. The
            // owner keeps the row; sharing never transfers ownership.
            $query->orWhere(function (Builder $query) use ($user): void {
                $query->where('is_shared', true)
                    ->whereIn('user_id', User::query()
                        ->select('id')
                        ->where('household_id', $user->household_id));
            });
        });
    }

    /**
     * Reminders that have not been completed.
     *
     * @param  Builder<Reminder>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('completed_at');
    }

    /**
     * Pending reminders whose moment has arrived — the delivery engine's
     * working set. Comparison is in UTC, like every stored datetime, and it
     * is made against the *effective* moment so a reminder snoozed into the
     * future stays out of the set until its snooze expires.
     *
     * @param  Builder<Reminder>  $query
     */
    #[Scope]
    protected function due(Builder $query, ?DateTimeInterface $at = null): void
    {
        $at = Carbon::instance($at ?? Carbon::now())->utc();

        $query->pending()
            ->whereRaw(self::EFFECTIVE_DUE_AT.' <= ?', [$at->format('Y-m-d H:i:s')])
            ->orderByRaw(self::EFFECTIVE_DUE_AT.' asc');
    }
}
