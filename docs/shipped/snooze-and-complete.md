# Snooze & Complete

**Status:** ✅ **Implemented — 2026-08-03** (manual check of notification buttons in a real browser still owed)

## Close-out

**Deviations:** No custom snooze picker UI (endpoint accepts an explicit local datetime
and it's tested; the dropdown ships the four presets). A third endpoint
`reminders.restore` exists for Undo (authorizes as `update`). Snooze's local→UTC
conversion lives in `app/Support/SnoozePresets.php` — a second doorway beyond
`ReminderRequest`, but still exactly one conversion per input shape. `due_label` still
describes `due_at`; snoozed rows additionally show a "Snoozed until …" badge. Index rows
render completed state with a two-way toggle. `bootstrap/app.php` gained a `then:`
closure to register `routes/notification-actions.php` outside the `web` group.

**Things later work must know:**
- Undo is stateless: `complete()` flashes the prior `{completed_at, due_at,
  snoozed_until}` snapshot as `toast.undo`; sonner posts it to `reminders.restore`.
  Nothing is held server-side.
- Notification action URLs are `URL::temporarySignedRoute`, TTL 7 days
  (`ReminderDueNotification::ACTION_URL_TTL_DAYS`), routes under
  `notification-actions/…` with only `['signed', SubstituteBindings::class]` — the
  signature IS the authorization; **production APP_URL must be correct or signatures
  break** (deployment-https must regenerate nothing but must set APP_URL before pushes
  go out).
- `sw.js notificationclick`: action buttons resolve `data[`{action}_url`]` and POST
  without opening a window; default click unchanged.
- Never delete `reminder_dispatches` rows on snooze — a new `snoozed_until` mints a new
  claim key, so re-firing is natural. `SnoozeRequest` refuses past custom times, which
  closes the key-collision edge.
- Policy abilities `complete`/`snooze` delegate to `isVisibleTo()` — household members
  can act on shared reminders (row-level).
- Suite at close: 213 tests / 813 assertions.

Acting on a reminder — from the app and, crucially, from the push notification itself.

## In-app actions

- **Complete**: sets `completed_at` (one-off) or advances to next occurrence (recurring —
  reuse the recurrence calculator). Checkbox/tap target on every list row and the Today
  view; completing shows a `sonner` toast with an **Undo** action (5 s window restores
  prior state).
- **Snooze**: sets `snoozed_until` (UTC); the delivery engine treats a snoozed reminder
  as due again when `snoozed_until` passes (a snoozed occurrence re-dispatches — the
  dispatch uniqueness key must incorporate this; use the effective fire time, or delete
  the dispatch row on snooze). Presets: 10 min, 1 h, 3 h, tomorrow morning (default time
  in local TZ), plus a custom picker. Snoozed rows show "snoozed until …" and are
  excluded from Overdue.
- Endpoints: `POST reminders/{reminder}/complete`, `POST reminders/{reminder}/snooze`
  (validated preset or datetime), policy-guarded, returning `back()`.

## Notification actions (the PWA payoff)

Extend the push pipeline so the notification itself carries buttons:

- `ReminderDueNotification::toWebPush()`: add actions `[{action: 'complete', title: 'Complete'},
  {action: 'snooze', title: 'Snooze 1h'}]` and put `reminder_id` in the data payload.
- `public/sw.js` `notificationclick`: when `event.action` is `complete`/`snooze`, `fetch()`
  a POST to a **signed URL** included in the push payload (`URL::signedRoute(...)`) —
  the service worker has no CSRF token or guaranteed session, and signed routes are the
  simplest auth that works from SW context. Add the two signed-route endpoints outside
  the session middleware but validating the signature + reminder id.
- Default click (no action) keeps existing behavior: focus/open `/today`.

## Acceptance criteria

- Complete and snooze work from list rows and Today view; Undo restores.
- Push notification on the phone/local Chrome shows both buttons; tapping **Complete**
  completes without opening the app; **Snooze 1h** re-pushes an hour later.
- Pest: endpoint auth/validation, snooze re-dispatch behavior, recurring-complete
  advances occurrence, signed-route tampering rejected.

## Open questions

1. Snooze presets — right set? (10 min / 1 h / 3 h / tomorrow morning.)
2. Should "tomorrow morning" use the settings default time (settings spec) — assume yes,
   hardcode 09:00 until that ships.
