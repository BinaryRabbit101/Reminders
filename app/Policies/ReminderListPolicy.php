<?php

namespace App\Policies;

use App\Http\Controllers\ReminderListController;
use App\Models\ReminderList;
use App\Models\User;

/**
 * Managing a list itself — reading its settings, renaming it, deleting it —
 * is owner-only, full stop. This is the one place the household rule
 * deliberately does not apply: a list is how one person organises their own
 * work, so it never crosses accounts for these actions.
 *
 * Filing a *reminder* into a list is a different question and is not decided
 * here — {@see ReminderListController::assign()} allows
 * it for anyone the reminder is visible to, own list or not, because that's a
 * fact about the reminder's visibility rather than the list's ownership.
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
