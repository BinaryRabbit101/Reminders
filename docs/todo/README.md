# Todo — Feature Specs

**Status:** 📝 **Planning — 2026-08-03**

Implementation order matters — each phase builds on the previous one. Within a phase,
specs are independent and can go to parallel sub-agents.

## Phase 1 — Core

| Doc | Feature | Depends on |
|---|---|---|
| ~~reminders-crud~~ | ✅ Shipped 2026-08-03 → [../shipped/reminders-crud.md](../shipped/reminders-crud.md) | |
| ~~today-view~~ | ✅ Shipped 2026-08-03 → [../shipped/today-view.md](../shipped/today-view.md) | |

## Phase 2 — PWA & push

| Doc | Feature | Depends on |
|---|---|---|
| ~~pwa-shell~~ | ✅ Shipped 2026-08-03 → [../shipped/pwa-shell.md](../shipped/pwa-shell.md) | |
| ~~push-notifications~~ | ✅ Shipped 2026-08-03 → [../shipped/push-notifications.md](../shipped/push-notifications.md) | |
| ~~delivery-engine~~ | ✅ Shipped 2026-08-03 → [../shipped/delivery-engine.md](../shipped/delivery-engine.md) | |

## Phase 3 — Reminder power features

| Doc | Feature | Depends on |
|---|---|---|
| ~~shared-reminders~~ | ✅ Shipped 2026-08-03 → [../shipped/shared-reminders.md](../shipped/shared-reminders.md) | |
| ~~recurrence~~ | ✅ Shipped 2026-08-03 → [../shipped/recurrence.md](../shipped/recurrence.md) | |
| ~~snooze-and-complete~~ | ✅ Shipped 2026-08-03 → [../shipped/snooze-and-complete.md](../shipped/snooze-and-complete.md) | |

## Phase 4 — Polish

| Doc | Feature | Depends on |
|---|---|---|
| ~~lists-and-tags~~ | ✅ Shipped 2026-08-03 → [../shipped/lists-and-tags.md](../shipped/lists-and-tags.md) | |
| ~~notification-history~~ | ✅ Shipped 2026-08-03 → [../shipped/notification-history.md](../shipped/notification-history.md) | |
| ~~settings-and-quiet-hours~~ | ✅ Shipped 2026-08-03 → [../shipped/settings-and-quiet-hours.md](../shipped/settings-and-quiet-hours.md) | |
| [deployment-https.md](deployment-https.md) | Serve over HTTPS on the mini-PC so push works in production | push-notifications |
| ~~scriptable-widget~~ | ✅ Shipped 2026-08-03 → [../shipped/scriptable-widget.md](../shipped/scriptable-widget.md) — E2E blocked on deployment-https | |

## Phase 5 — Alerting refinements

| Doc | Feature | Depends on |
|---|---|---|
| ~~pre-alerts~~ | ✅ Shipped 2026-08-19 → [../shipped/pre-alerts.md](../shipped/pre-alerts.md) | |
| ~~auto-complete-on-dispatch~~ | ✅ Shipped 2026-08-19 → [../shipped/auto-complete-on-dispatch.md](../shipped/auto-complete-on-dispatch.md) | |
