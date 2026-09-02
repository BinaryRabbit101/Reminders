# Shipped

**Status:** ✅ **15 features shipped — updated 2026-08-24**

Specs are moved here from [../todo/](../todo/) when implemented. Each entry gets a ✅
status line, the ship date, and a close-out section covering deviations from the spec and
**Things later work must know**.

| Doc | Feature | Shipped | Notes |
|---|---|---|---|
| [reminders-crud.md](reminders-crud.md) | Reminder model + CRUD UI | ✅ 2026-08-03 | Local→UTC lives solely in `ReminderRequest`; client never does zone math. Wayfinder needs `--with-form`. |
| [pwa-shell.md](pwa-shell.md) | Manifest, network-only SW, icons, installability | ✅ 2026-08-03 | SW registration in `lib/serviceWorker.ts` — push.ts must not re-register. |
| [today-view.md](today-view.md) | `/today` landing: Overdue / Today / Upcoming | ✅ 2026-08-03 | Bucketing in `TodayBoard`, formatting in `ReminderPresenter`; DST regression-tested. |
| [push-notifications.md](push-notifications.md) | VAPID, subscribe flow, `push:test` | ✅ 2026-08-03 | ⚠ composer install/require broken on this box (Pest 5 vs PHP 8.4.0) — see close-out. Browser delivery check still manual. |
| [delivery-engine.md](delivery-engine.md) | `reminders:send-due` + `ReminderDueNotification` | ✅ 2026-08-03 | Dispatch keys on the *effective* occurrence; claim-then-send is the send-once guarantee. |
| [shared-reminders.md](shared-reminders.md) | Household: private vs shared, push fan-out to both accounts | ✅ 2026-08-03 | Visibility = `visibleTo`/`isVisibleTo`; policy delegates, so new abilities inherit the rule. |
| [recurrence.md](recurrence.md) | Repeating reminders, DST-safe local-time math | ✅ 2026-08-03, amended 2026-08-07 | Advance hangs off completion only (2026-08-07) — the delivery engine never moves `due_at`; `advanceOrComplete()` is the seam, called solely by `complete()`. |
| [snooze-and-complete.md](snooze-and-complete.md) | Complete/snooze/undo + push notification action buttons | ✅ 2026-08-03 | Signed action URLs need correct production APP_URL; never delete dispatch rows on snooze. |
| [lists-and-tags.md](lists-and-tags.md) | Personal lists, colors, filter, push-title prefix | ✅ 2026-08-03 | Lists are per-user even on shared reminders; hex emitted server-side. |
| [notification-history.md](notification-history.md) | `/history` feed, unread badge, 90-day prune | ✅ 2026-08-03 | Unread count shared as a closure — resolves post-controller; scoped to ReminderDueNotification. |
| [settings-and-quiet-hours.md](settings-and-quiet-hours.md) | Per-user timezone/default-time, quiet hours + held pushes | ✅ 2026-08-03 | Quiet hours delay the push channel only; `held_pushes` drained by the same command. |
| [scriptable-widget.md](scriptable-widget.md) | Per-user token feed + `reminders.js` iOS widget | ✅ 2026-08-03 | Constant-time token scan; widget CONFIG needs port+token after deployment. |
| [pre-alerts.md](pre-alerts.md) | Snoozable "1 h / 1 day before" alerts with their own dispatch log | ✅ 2026-08-19 | Alerts anchor to raw `due_at`; alert snoozes cleared on advance/due edit; skip-without-claim gate is load-bearing. |
| [auto-complete-on-dispatch.md](auto-complete-on-dispatch.md) | Opt-in: recurring reminders advance at dispatch instead of parking in Overdue | ✅ 2026-08-19 | The one exception to the 2026-08-07 recurrence amendment; advance hangs off the claim; no completion row. |
| [silenced-reminders.md](silenced-reminders.md) | Per-reminder toggle: deliver in-app only, never a push | ✅ 2026-08-24 | Silence is the *reminder's* property (quiet hours are the recipient's); short-circuits ahead of the quiet-hours split, holds nothing, covers pre-alerts, drops already-held pushes. Primary surface is the row's snooze menu, not the edit sheet. |
| [quick-add-shortcut.md](quick-add-shortcut.md) | Token-authed `POST /api/shortcut/reminders` + the iOS Shortcut recipe | ✅ 2026-09-02 | A *second* bearer column: `shortcut_token` writes, `widget_token` reads, neither resolves on the other's route. Local→UTC now lives in `App\Support\DueMoment` (both writers call it). Every response carries `message` so the Shortcut shows one dictionary key whatever happened. |
