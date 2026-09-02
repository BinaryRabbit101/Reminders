<?php

namespace App\Support;

use App\Http\Requests\ReminderRequest;
use App\Http\Requests\ShortcutReminderRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The one place a wall-clock date and time become a moment.
 *
 * Everything that creates or edits a reminder posts *parts* — a calendar date
 * and an optional time — never a timestamp, because that is what a person
 * types and what a `<input type="date">` and a Shortcut's date prompt both
 * hand over. Those parts mean nothing until they are read on somebody's
 * clock, and reading them on the wrong one moves a reminder by hours.
 *
 * So both writers come through here: the web form
 * ({@see ReminderRequest}) and the quick-add endpoint the
 * iPhone Shortcut posts to
 * ({@see ShortcutReminderRequest}). The zone is the
 * *poster's* ({@see User::timezone()}), falling back to
 * `config('reminders.timezone')` for the accountless case; a missing time
 * falls back to their own default reminder hour. The result is local
 * wall-time — callers convert to UTC on the way to the database and nothing
 * converts again (ARCHITECTURE.md §1).
 */
final class DueMoment
{
    /**
     * The moment a date and an optional time name, on the poster's clock.
     *
     * @param  User|null  $user  Whose clock to read it on; null means the app
     *                           default, which is what an unauthenticated
     *                           caller would get.
     * @param  string  $date  A `Y-m-d` calendar day.
     * @param  string|null  $time  A `H:i` wall time, or null/blank for the
     *                             account's default reminder time.
     */
    public static function local(?User $user, string $date, ?string $time): Carbon
    {
        $timezone = $user instanceof User
            ? $user->timezone()
            : (string) config('reminders.timezone');

        $time = trim((string) $time);

        if ($time === '') {
            $time = self::defaultTime($user);
        }

        return Carbon::parse(trim($date).' '.$time, $timezone);
    }

    /**
     * The hour a date-only reminder lands at, for whoever is posting.
     */
    public static function defaultTime(?User $user): string
    {
        return $user instanceof User
            ? $user->defaultTime()
            : (string) config('reminders.default_time');
    }

    /**
     * Right now, on the poster's clock.
     *
     * Here rather than at the call site so "the user's today" is decided by
     * the same seam that decides what their 5pm means — a quick-add with no
     * date has to land on the day *they* are having, not the server's.
     */
    public static function now(?User $user): Carbon
    {
        return Carbon::now($user instanceof User
            ? $user->timezone()
            : (string) config('reminders.timezone'));
    }
}
