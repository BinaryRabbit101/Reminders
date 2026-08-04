<?php

namespace App\Policies;

use App\Models\ReminderList;
use App\Models\User;

/**
 * Lists are owner-only, full stop.
 *
 * This is the one place the household rule deliberately does *not* apply.
 * A reminder can be shared because it is a job two people might do; a list is
 * how one person files their own work, so it never crosses accounts — not to
 * be read, not to be renamed, not to be assigned to.
 */
class ReminderListPolicy
{
    /**
     * Determine whether the user can update the list.
     */
    public function update(User $user, ReminderList $list): bool
    {
        return $list->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the list.
     */
    public function delete(User $user, ReminderList $list): bool
    {
        return $list->user_id === $user->id;
    }
}
