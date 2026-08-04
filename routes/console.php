<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The delivery engine. Runs every minute because a reminder set for 9:00
// should arrive at 9:00, not at the top of the hour.
//
// TIMEZONE STANCE — read before adding anything else here.
// Laravel schedules in `app.timezone` (UTC), and every datetime this app
// stores is UTC too (ARCHITECTURE.md §1). This entry is safe *because it has
// no time-of-day component*: "every minute" is the same set of instants in
// every timezone, and the command compares UTC `due_at` against UTC `now()`.
// The local timezone matters only when a wall-clock time is chosen, and that
// happens on the way in (ReminderRequest) and on the way out (presentation).
//
// Any future entry that IS time-of-day-meaningful — a daily digest, a
// morning summary — must chain `->timezone(config('reminders.timezone'))`,
// or a bare dailyAt('07:30') fires at 07:30 UTC. StoryCampaign learned this
// by shipping a "the world changed overnight" push at 11:30 PM local.
//
// `withoutOverlapping()` keeps a slow run from being lapped by the next
// tick. The send-once guarantee does not depend on it (that is the unique
// index on reminder_dispatches), but overlapping runs waste work.
Schedule::command('reminders:send-due')->everyMinute()->withoutOverlapping();

// Housekeeping for the in-app notification history: read entries older than
// 90 days go away.
//
// This one has a time of day (`daily()` is midnight) and still chains no
// timezone, which is the stance above applied rather than broken: the rule is
// that an entry becomes prunable 90 days after it was sent, and that
// comparison is UTC-against-UTC inside the command. Nobody ever observes the
// hour this runs at — there is no wall-clock promise to keep, unlike a morning
// digest, which would need `->timezone(config('reminders.timezone'))`. The
// worst a timezone shift could do here is move the deletion of a 90-day-old
// row by a few hours.
Schedule::command('reminders:prune-notifications')->daily()->withoutOverlapping();
