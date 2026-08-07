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
 * @property Carbon $occurred_at
 * @property Carbon $release_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Reminder|null $reminder
 */
#[Fillable(['user_id', 'reminder_id', 'occurred_at', 'release_at'])]
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
