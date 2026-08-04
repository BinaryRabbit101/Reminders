# Settings & Quiet Hours

**Status:** ✅ **Implemented — 2026-08-03** (manual quiet-window browser check still owed)

## Close-out

**Deviations:** Own `/settings/reminders` page (device-scoped push controls stay on
Notifications; these are account preferences — the pages cross-link). Curated timezone
list (7 US zones + UTC + "App default"); full searchable list skipped (needs an
unvendored combobox). Quiet-hours window inputs stay enabled while the toggle is off —
a disabled input posts nothing and would silently reset the saved window.

**Things later work must know:**
- Storage: scalar columns on `users` (`timezone`, `default_time` nullable = app
  default; `quiet_hours_enabled/start/end`). `protected $attributes` seeds defaults —
  removing that seed breaks `User::timezone()` (column and accessor share the name).
- Quiet hours live **inside the send step only** — claiming, dispatch keys, stale
  suppression, advancing all unchanged. Quiet recipients get the `database` channel
  immediately + a `held_pushes` row; `sent_at` still set (null still means exactly
  "stale claim"). Holds drain at the top of the same per-minute command,
  delete-then-send (the row is the claim), WebPush channel only (no second history
  row). Holds are dropped if the reminder is gone/completed/re-snoozed past the
  occurrence. Stale window does NOT apply to released holds.
- Window is half-open `[start, end)` local wall clock; midnight-spanning works;
  malformed/equal values cover nothing (never throw in the sweep).
- Timezone seams: `User::timezone()/defaultTime()/quietHours()`;
  `ReminderPresenter::for()`, `SnoozePresets::for()`, `RecurrenceCalculator::for()`.
  Acting user's zone for entry/display/snooze; **owner's zone** for scheduler
  advancing and signed notification actions (no acting user there).
- Suite at close: 300 tests / 1257 assertions.

Per-user preferences that tune the delivery engine.

## Settings

Add a "Reminders" section under the existing `resources/js/pages/settings/` area
(alongside Profile/Appearance/Security), backed by either columns on `users` or a
`user_settings` JSON column — pick one, document in close-out:

| Setting | Default | Effect |
|---|---|---|
| Timezone | `config('reminders.timezone')` | overrides the app-level display/recurrence TZ per user |
| Default reminder time | 09:00 | used when only a date is picked (CRUD) and for "tomorrow morning" snooze |
| Quiet hours | off (e.g. 22:00–07:00 when on) | pushes due inside the window are **held** and delivered at the window's end; the Today view still shows them immediately |

## Quiet-hours mechanics

Delivery engine change: a reminder due inside quiet hours gets its dispatch deferred —
simplest correct model is treating it like an automatic snooze to quiet-hours end
(local-time window → UTC conversion per occurrence, DST-safe via the same recurrence
calculator utilities). It must still appear as Overdue/Today in the UI immediately; only
the *push* is held.

## Also fold in

- The "Enable notifications on this device" control (from push-notifications spec) gets
  its permanent home here, showing per-device subscription state.
- Timezone select: use a curated short list (US zones + UTC) rather than all 400 IANA
  zones, with the full list behind a search — shadcn `select` + input.

## Acceptance criteria

- Changing default time affects new date-only reminders and tomorrow-morning snooze.
- A reminder due 23:00 with quiet hours 22:00–07:00 pushes at 07:00 and shows in the app
  at 23:00. Pest tests incl. a quiet-hours window spanning midnight.

## Open questions

1. Are quiet hours actually wanted, or is the phone OS's own Do Not Disturb enough? (If
   the latter, move this doc's quiet-hours half to discontinued/ with that reasoning.)
