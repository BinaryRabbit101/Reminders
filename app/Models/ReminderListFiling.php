<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A household member's own filing of a reminder they don't own — the
 * co-filer's half of "lists are personal": the owner's filing always lives on
 * `reminders.list_id`, never here. `user_id` is therefore never the
 * reminder's own owner; that invariant is held by `ReminderListController`'s
 * `assign()`/`unassign()` branching before either write, not by a DB
 * constraint.
 *
 * @property int $id
 * @property int $reminder_id
 * @property int $user_id
 * @property int $list_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Reminder $reminder
 * @property-read User $user
 * @property-read ReminderList $list
 */
#[Fillable(['reminder_id', 'user_id', 'list_id'])]
class ReminderListFiling extends Model
{
    /**
     * The reminder this filing is for.
     *
     * @return BelongsTo<Reminder, $this>
     */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }

    /**
     * The household member who filed it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The filer's own list it was filed under.
     *
     * @return BelongsTo<ReminderList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(ReminderList::class, 'list_id');
    }
}
