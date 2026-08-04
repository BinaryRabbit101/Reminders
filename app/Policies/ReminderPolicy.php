<?php

namespace App\Policies;

use App\Models\Reminder;
use App\Models\User;

/**
 * Acting on a reminder is the same question as seeing it.
 *
 * A shared reminder is one row, not two copies: either household member may
 * edit it, and — snooze and complete authorize through the same rule — either
 * may tick it off for both. Private reminders stay owner-only, household or
 * no household.
 *
 * Every ability here delegates to {@see Reminder::isVisibleTo()}. That is the
 * point: the household rule lives in one place, so an ability added later
 * inherits it by being written the same way.
 */
class ReminderPolicy
{
    /**
     * Determine whether the user can update the reminder.
     */
    public function update(User $user, Reminder $reminder): bool
    {
        return $reminder->isVisibleTo($user);
    }

    /**
     * Determine whether the user can delete the reminder.
     */
    public function delete(User $user, Reminder $reminder): bool
    {
        return $reminder->isVisibleTo($user);
    }

    /**
     * Determine whether the user can complete the reminder.
     */
    public function complete(User $user, Reminder $reminder): bool
    {
        return $reminder->isVisibleTo($user);
    }

    /**
     * Determine whether the user can snooze the reminder.
     */
    public function snooze(User $user, Reminder $reminder): bool
    {
        return $reminder->isVisibleTo($user);
    }
}
