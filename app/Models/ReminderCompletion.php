<?php

namespace App\Models;

use App\Support\NotificationHistory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per "a reminder got completed" — the log `completed_at` on
 * {@see Reminder} itself cannot be: that column is overwritten the moment a
 * recurring reminder advances to its next occurrence, so it can only ever
 * answer "is this reminder done right now", never "what got done, and when."
 * This table exists so the history page can answer the second question.
 *
 * Written once, by {@see Reminder::complete()}, and never updated — Undo
 * restores the reminder's own state but leaves the log entry alone, because
 * the completion genuinely happened even if the user changed their mind
 * afterward. `title`, `is_shared`, and `occurred_at` are snapshots for the
 * same reason {@see NotificationHistory} snapshots them: the
 * entry has to keep reading correctly after the reminder is retitled,
 * un-shared, or deleted out from under it.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $reminder_id
 * @property string $title
 * @property bool $is_shared
 * @property Carbon $occurred_at
 * @property Carbon $completed_at
 * @property-read User $user
 * @property-read Reminder|null $reminder
 */
#[Fillable(['user_id', 'reminder_id', 'title', 'is_shared', 'occurred_at', 'completed_at'])]
class ReminderCompletion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
            'occurred_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * The reminder's owner at the moment it was completed.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The reminder this entry is about, or null once it has been deleted.
     *
     * @return BelongsTo<Reminder, $this>
     */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }

    /**
     * Who may see this entry — the same shape as {@see Reminder::visibleTo()}
     * and deliberately kept in step with it: a completion is visible to
     * exactly the audience the reminder itself was visible to when it
     * happened, which `is_shared` here has already snapshotted.
     *
     * @param  Builder<ReminderCompletion>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->where('user_id', $user->id);

            if ($user->household_id === null) {
                return;
            }

            $query->orWhere(function (Builder $query) use ($user): void {
                $query->where('is_shared', true)
                    ->whereIn('user_id', User::query()
                        ->select('id')
                        ->where('household_id', $user->household_id));
            });
        });
    }
}
