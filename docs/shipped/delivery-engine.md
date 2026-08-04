# Delivery Engine

**Status:** ✅ **Implemented — 2026-08-03** (manual end-to-end with a live browser still owed)

## Close-out

**Deviations:** `reminder_dispatches.due_at` stores the **effective** occurrence
(`coalesce(snoozed_until, due_at)`), not the raw column — otherwise a snoozed reminder
could never re-fire (its original key would already be claimed). `sent_at` is nullable to
distinguish stale-suppressed claims (recorded, never pushed) from real sends. The `due()`
scope was rewritten to the coalesce form; `TodayBoard` now shares the same
`Reminder::EFFECTIVE_DUE_AT` constant so the engine and the UI have one definition of
"the moment".

**Things later work must know:**
- Send-once = insert-first into `reminder_dispatches` (unique `['reminder_id','due_at']`),
  catch `UniqueConstraintViolationException`, only send after a successful claim. Never
  reorder this.
- `ReminderDueNotification` takes `$occurredAt` explicitly; its database payload is
  `['reminder_id', 'title', 'due_at' (ISO-8601 UTC)]`, byte-identical to the dispatch
  row — notification-history can join on `(reminder_id, due_at)`.
- WebPush tag is `reminder-{id}` so a post-snooze re-send **replaces** the phone
  notification instead of stacking.
- Stale window: occurrences older than 10 min (`SendDueReminders::STALE_AFTER_MINUTES`)
  are claimed but not pushed (`sent_at` stays null) — a backlog can never fire a wall of
  pushes.
- Recipient seam: `Reminder::recipients()` currently returns the owner;
  shared-reminders extends it — do not inline `$reminder->user` at send sites.
- Suite at close: 93/93 tests, 389 assertions. Manual E2E owed: `php artisan
  schedule:work` + subscribed browser → exactly one push at the due minute.

The heart of the app: a scheduled command that finds due reminders and pushes them to the
phone, exactly once per occurrence.

## Behavior

- Every minute, `reminders:send-due` selects reminders that are **due**: `due_at <= now()`,
  not completed, not snoozed into the future, and **not already dispatched** for this
  `due_at`.
- Recipient resolution must be a single seam (e.g. a `Reminder::recipients()` method
  returning a collection) — today it returns just the owner, but the shared-reminders
  spec extends it to all household members for shared reminders. Don't inline
  `$reminder->user` at the send site.
- Each hit sends `ReminderDueNotification` to each recipient via
  `[WebPushChannel::class, 'database']` — push to every subscribed device + an in-app
  record (consumed later by notification-history).
- A `reminder_dispatches` row records the send: `reminder_id`, `due_at` (the occurrence),
  `sent_at`. Unique index on `['reminder_id', 'due_at']` — this is the send-once guarantee.

## Send-once under SQLite

Row locks are no-ops in SQLite (ARCHITECTURE.md §5). The guarantee comes from the unique
index: insert the dispatch row **first** inside a try/catch on the unique-violation, and
only send the notification if the insert succeeded. A crashed run can at worst record a
dispatch without sending — acceptable; the reverse (double push) is not.

## Implementation

- `app/Console/Commands/SendDueReminders.php` — model on LittlePocketMeseum's
  `app\Console\Commands\SendWishlistReminders.php` (query → decide → `Notification::send`).
- `app/Notifications/ReminderDueNotification.php` — model on LittlePocketMeseum's
  `WishlistReminderNotification.php`: `via()` returns `[WebPushChannel::class, 'database']`;
  `toWebPush()` returns a `WebPushMessage` with the reminder title as notification title,
  notes excerpt as body, badge/icon from `public/icons/`, and a data `url` deep-linking to
  `/today` (the sw.js `notificationclick` handler navigates there).
- `routes/console.php`:
  ```php
  Schedule::command('reminders:send-due')->everyMinute()->withoutOverlapping();
  ```
  Read StoryCampaign's `routes/console.php` comments first — the per-minute checker is
  timezone-safe (UTC compare), but copy its `->withoutOverlapping()` discipline. Any
  future time-of-day schedule must chain `->timezone(config('reminders.timezone'))`.
- Config: create `config/reminders.php` with `'timezone' => env('REMINDERS_TIMEZONE', 'America/Chicago')`
  if the CRUD spec hasn't already.
- Overdue-on-arrival: a reminder created with `due_at` already in the past should **not**
  fire a push (dispatch guard: only send when `due_at` is within the last N minutes,
  N = 10, else record dispatch silently). Prevents a wall of stale pushes after downtime.

## Acceptance criteria

- With `schedule:work` running locally and a subscribed browser: create a reminder due in
  1–2 minutes → exactly one push arrives at the right minute; a second scheduler tick
  does not re-send.
- Pest tests (freeze time): due selection, snoozed exclusion, completed exclusion,
  dispatch-uniqueness (running the command twice sends once), stale-due suppression.
- `composer test` green.

## Open questions

1. Stale-window N=10 minutes — reasonable? (Downtime longer than that means missed
   pushes appear only in Overdue on the Today view.)
