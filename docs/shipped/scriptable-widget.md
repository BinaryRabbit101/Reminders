# Scriptable Widget

**Status:** ✅ **Implemented — 2026-08-03** (phone-over-Tailscale blocked on deployment-https; widget CONFIG carries `<PORT-TBD>`)

## Close-out

**Deviations:** `today[]` rows carry a 4th field `is_overdue` (the widget can't paint red
rows it can't see) — the array is the *attention list*: overdue first then today,
soonest first, capped at 6; `overdue_count`/`pending_total` are true separate counts.
Overdue rows from earlier days show "Aug 1" instead of a bare time. `next_upcoming` is
next-after-*now*; the medium footer self-suppresses when redundant. No offline cache —
the error card is the honest failure. `throttle:60,1` added (bearer token on an
unauthenticated route). All failures are a uniform 403.

**Things later work must know:**
- `User::byWidgetToken()` `hash_equals`-scans all token holders **without early break**
  (constant-time; don't "optimize" into a WHERE clause). Token is 48 chars, `Hidden`,
  not fillable; regenerating revokes instantly.
- Feed route lives in `routes/widget.php`, registered via the same `bootstrap/app.php`
  `then:` closure as notification-actions (prefix `api/widget`, name `widget.`,
  throttle only).
- Payload assembly in `app/Support/WidgetFeed.php` (TodayBoard-style `for()` seam);
  all times formatted server-side on `User::timezone()`.
- **Deployment must**: fill the widget CONFIG's real port + token, and `open_url`
  follows `config('app.url')` — correct APP_URL matters here too.
- Widget file: `Scriptables\widgets\reminders.js` (that repo's conventions; deploy via
  its `/deploy-widget` skill after CONFIG is filled).
- Suite at close: 332 tests / 1370 assertions.

An iOS home-screen widget (Scriptable app) showing what's due — overdue count, today's
reminders, and the next upcoming one. Two deliverables in two repos: a read-only widget
feed endpoint in this app, and a widget script in the Scriptables project at
`C:\Users\binar\OneDrive\Documents\Claude\Projects\Scriptables`.

## Established pattern (copy it)

The StoryCampaign widget is the model end to end:

- **Feed**: `GET /api/widget/status?token=<long-random-token>` on the Laravel app —
  token-authenticated (env-held token, no session), read-only JSON, served to the phone
  over Tailscale HTTPS (`https://minipc.jackal-hippocampus.ts.net:<port>`).
- **Widget**: `Scriptables\widgets\story-campaign.js` — single self-contained file:
  reference line → `CONFIG` (baseUrl + token) → palette consts → fetch with
  `timeoutInterval = 15` → small/medium layouts via `config.widgetFamily` → catch-block
  error card ("Is Tailscale connected?") → `Script.setWidget` / preview guard →
  `Script.complete()`.
- The Scriptables project has skills for the whole loop: `/new-widget`,
  `/verify-widget`, `/deploy-widget` (copies to the iCloud Scriptable folder), and
  `/deploy-widget-endpoint` (deploys a Laravel feed to the mini-PC and wires the widget
  to it). The implementing agent should work from that project's conventions, not invent.

## Laravel side (this repo)

- Route `GET /api/widget/today` outside the auth group, token-guarded — but **per-user**,
  not app-wide (two accounts exist; see shared-reminders spec): a `widget_token` column
  on `users` (random 40+ chars, shown/regenerable in settings), resolved with
  `hash_equals` against the query token, 403 on mismatch. Each phone's widget CONFIG
  carries its owner's token, and the feed returns that user's *visible* reminders
  (`Reminder::visibleTo($user)` — includes household-shared ones).
- Response (all times pre-formatted server-side in `config('reminders.timezone')` —
  the widget should not do timezone math):

```json
{
  "overdue_count": 2,
  "today": [ { "time": "9:00 AM", "title": "Take out bins", "list_color": "#7ee0a0" } ],
  "next_upcoming": { "when": "Tomorrow 9:00 AM", "title": "Water plants" },
  "pending_total": 14,
  "open_url": "https://minipc.jackal-hippocampus.ts.net:<port>/today"
}
```

- Cap `today` at ~6 rows; exclude completed; snoozed items show at their snoozed time.
- Pest tests: token auth (valid/invalid/missing), payload shape, timezone formatting.

## Widget side (Scriptables repo)

- `widgets/reminders.js` from `templates/widget-template.js`, matching the
  story-campaign.js shape and its dark palette conventions (`bg #0a0a0c` family).
- **Small**: overdue count (red when > 0) + next reminder. **Medium**: header with
  counts, then today's list rows (time dimmed, title bright), overdue rows in red.
  Empty state: "All clear."
- `widget.url` → the app's `/today` so a tap opens the PWA.
- `widget.refreshAfterDate` hint ~15 min; iOS decides the real cadence — the widget is a
  glanceable, not the notifier (push does that job).
- Deploy via `/verify-widget` then `/deploy-widget`.

## Dependencies

Reminders CRUD (data to show) must exist. Full end-to-end (phone over Tailscale) needs
deployment-https shipped; until then the endpoint is testable locally and the widget's
CONFIG just points at the eventual URL.

## Acceptance criteria

- Endpoint returns correct buckets/formatting (Pest, frozen time, Chicago DST edge).
- `node --check` passes on the widget; widget renders small + medium in Scriptable
  preview with live data from the deployed endpoint; error card appears when
  unreachable; tap opens the app.
