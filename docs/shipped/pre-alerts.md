# Pre-Alerts

**Status:** ✅ **Implemented — 2026-08-19**

## Close-out

**Deviations:**
- The fire gate was tightened during implementation (spec amended to match): an alert
  fires when `effectiveFireAt() <= now && now < reminder->effectiveDueAt()`. The spec's
  original `fireAt < effectiveDueAt` form was implied by the first condition and missed
  the case where *both* moments were already past inside the stale window — a short
  offset would have double-pushed alongside the real notification in the same tick after
  downtime. Regression-tested.
- The alert working set filters pending reminders via
  `whereIn('reminder_id', Reminder::query()->pending()->select('id'))` rather than
  `whereHas` — a `whereHas` closure loses the `Builder<Reminder>` generic and Larastan
  rejects the `pending()` scope on it. Same semantics, same seam.
- `held_pushes`' unique index stays `(user_id, reminder_id, occurred_at)` — widening it
  to include nullable `reminder_alert_id` would make NULLs distinct under SQLite and
  break the main path's idempotence. Safe because an alert fires strictly before the
  main moment, so their `occurred_at`s can never collide.
- The database payload's `due_at` is the RAW `due_at`, not the effective one — it keeps
  `fire_at + offset_minutes == due_at` exactly true and matches the anchoring rule.
- `NotificationHistory::TYPE` became `TYPES` (both notification classes), so pre-alerts
  appear in the feed/badge and are cleared on visit.
- The signed alert-snooze route is keyed on `{alert}` alone (the signature covers it; a
  reminder path segment would just be a second thing to keep in step).
- The list-surface bell is a glyph-only pill (`AlertsBadge.vue`) — labels live in its
  `title`; up to nine horizons inline would blow past 375px. No in-app snooze UI for
  alerts shipped (the web endpoint exists and is tested; snoozing happens from the push
  button), matching the snooze-and-complete precedent.

**Things later work must know:**
- `reminder_alert_dispatches` is a **separate** claim table; `reminder_dispatches` is
  untouched and its `(reminder_id, due_at)` history join still holds.
- Alerts anchor to RAW `due_at`, never `coalesce(snoozed_until, due_at)`. Alert
  `snoozed_until` belongs to the current occurrence: it is cleared by
  `Reminder::advanceOrComplete()` (advanced branch) and by `ReminderController@update`
  when `due_at` changes — without that, the coalesce would pin `effectiveFireAt()` in
  the past and the next occurrence's alert would never fire. Alert paths must never
  write `reminders.snoozed_until`.
- The gate's rejection branch skips **without claiming** — this is load-bearing: a
  snoozed alert's fire key is pinned by `snoozed_until`, so a claim written while the
  alert is temporarily meaningless would burn it permanently.
- Pre-alert push tag is `reminder-{reminderId}-alert-{alertId}` — it must never collide
  with the main `reminder-{id}` tag or the bubbles replace each other.
- `held_pushes.reminder_alert_id` has no FK (SQLite can't add one post-creation);
  integrity is in code — `HeldPush::isPreAlertSuperseded()` drops a held alert push when
  the alert is gone, the reminder is completed, the fire moment moved, or the main
  moment has arrived.
- Offsets allow-list is `ReminderAlert::OFFSETS`; form omission of `alerts[]` means
  "remove all" (same contract as `repeat_weekdays[]`). Sync preserves an existing row's
  `snoozed_until` when its offset is untouched.
- Suite at close (combined with auto-complete-on-dispatch, shipped same day): 438 Pest
  tests / 1814 assertions, 24 Dusk tests / 95 assertions, Larastan clean.

Alerts *before* a reminder is due — "also alert me 1 hour before" / "1 day before" — with
the ability to snooze the pre-alert itself (from the push notification and in-app) without
touching the main reminder's schedule.

Read [../ARCHITECTURE.md](../ARCHITECTURE.md) first, then the close-outs of
[delivery-engine.md](delivery-engine.md), [snooze-and-complete.md](snooze-and-complete.md)
and [recurrence.md](recurrence.md) (including its 2026-08-07 amendment) — this feature
composes with all three.

## Model

New table `reminder_alerts` — one row per pre-alert, several allowed per reminder:

| Column | Type | Meaning |
|---|---|---|
| reminder_id | FK → reminders, cascade | |
| offset_minutes | unsignedInteger | fire this many minutes before `due_at` |
| snoozed_until | dateTime nullable (UTC) | snooze of *this alert's current occurrence* |
| timestamps | | |

Unique `['reminder_id', 'offset_minutes']`. Allowed offsets (validation allow-list, a
constant on the `ReminderAlert` model): 5, 10, 15, 30, 60, 120, 1440, 2880, 10080
(5/10/15/30 min, 1/2 h, 1 day, 2 days, 1 week).

New table `reminder_alert_dispatches` — the alert twin of `reminder_dispatches`:
`reminder_alert_id` (FK cascade), `fire_at` (the effective alert moment), `sent_at`
nullable, unique `['reminder_alert_id', 'fire_at']`. A **separate** table on purpose:
`reminder_dispatches` stays byte-identical to the notification-history join key
(`reminder_id`, `due_at`) — do not add a `kind` column there.

`ReminderAlert` model: `Fillable` PHP attribute (project style), casts, `reminder()`
relation, `snoozeUntil()` (mirror `Reminder::snoozeUntil()`), and the load-bearing method:

```php
// The moment this alert wants to fire. Anchored to the RAW due_at, not the
// effective one: snoozing the main reminder moves the main occurrence only —
// pre-alerts stay anchored to the scheduled time. Snoozing the ALERT mints a
// new fire moment (and therefore a new dispatch key), exactly like the main
// snooze mechanic.
public function effectiveFireAt(): CarbonImmutable; // coalesce(snoozed_until, reminder.due_at − offset_minutes)
```

`Reminder` gains `alerts(): HasMany` and `'alerts'` handling described below. Add a
`ReminderAlertFactory`.

## Delivery engine — second pass in `reminders:send-due`

In `app/Console/Commands/SendDueReminders.php`, after the main loop, a pre-alert pass:

- Working set: alerts whose reminder is `pending()` (join/whereHas), eager-loading
  `reminder.user` and `reminder.list`. Filter **in PHP** by `effectiveFireAt()` — the
  arithmetic-in-SQL alternative is SQLite-specific and the row count is personal-scale.
- Fire when `effectiveFireAt() <= now` **and** `now < reminder->effectiveDueAt()`
  (strictly before the main moment — once it has arrived, the pre-alert is noise; the main
  notification is coming anyway, possibly in this same tick after downtime).
- When the strictly-before condition fails, **skip without claiming** — if the user later
  pushes `due_at` out, the alert becomes meaningful again and must still be able to fire.
- When it fires: claim first (insert `reminder_alert_dispatches`, catch
  `UniqueConstraintViolationException`), then the same stale window
  (`STALE_AFTER_MINUTES = 10`): stale → claimed with `sent_at` null, never pushed.
- Quiet hours: same per-recipient split as the main pass (reuse/extract the split — keep
  it one mechanism, but don't over-abstract). Held pre-alert pushes go to `held_pushes`
  with a new nullable `reminder_alert_id` column. **SQLite cannot add an FK to an existing
  table** (ARCHITECTURE.md §5) — plain indexed `unsignedBigInteger`, integrity in code.
  `releaseHeldPushes()` sends `ReminderPreAlertNotification` (push-only channels) when
  `reminder_alert_id` is set. `HeldPush::isSuperseded()` for the alert case: superseded
  when the alert row is gone, the reminder is completed, the alert's current
  `effectiveFireAt()` no longer equals `occurred_at`, or it is no longer strictly before
  `reminder->effectiveDueAt()`.

## Notification

`app/Notifications/ReminderPreAlertNotification.php`, modeled on
`ReminderDueNotification` (same channel constants / quiet-hours split support):

- Title: same `pushTitle()` shape. Body leads with the horizon: "Due in 1 hour." /
  "Due in 1 day." (humanized from `offset_minutes`), then the notes excerpt if any.
- **Tag: `reminder-{reminderId}-alert-{alertId}`** — must NOT reuse `reminder-{id}` or the
  pre-alert bubble would replace/be replaced by the main one.
- Actions: `Complete` → the existing signed `notification-actions.complete` URL (completing
  early from a pre-alert is legitimate), and `Snooze 10m` → new signed route below with
  `preset=10m` (a 1 h default would routinely overshoot the due moment; add a
  `SnoozePresets::PRE_ALERT_NOTIFICATION_DEFAULT = '10m'`). `sw.js` needs **no changes**
  — it generically resolves `data[`{action}_url`]`.
- Database channel payload must be distinguishable from a due notification:
  `['kind' => 'pre_alert', 'reminder_id', 'title', 'due_at' (main occurrence, ISO-8601 UTC),
  'fire_at', 'offset_minutes']`. Read `app/Support/NotificationHistory.php` and the history
  UI and make pre-alert entries render sensibly (e.g. "Alerted 1 hour before"); entries
  without `kind` keep behaving exactly as today.

## Snoozing a pre-alert

- Signed route (no session/CSRF — service-worker context), in
  `routes/notification-actions.php`: `POST notification-actions/alerts/{alert}/snooze`,
  name `notification-actions.alerts.snooze`, `preset` inside the signature, modeled on
  `NotificationActionController::snooze` (422 on unknown preset).
- Web route (auth+verified): `POST reminders/{reminder}/alerts/{alert}/snooze` →
  `ReminderAlertController@snooze` (or a method on `ReminderActionController` — pick what
  reads best), scoped bindings, reusing `SnoozeRequest` (preset or custom local datetime;
  refuses past moments). Authorize with the existing `snooze` ability on the reminder.
- Both write only `reminder_alerts.snoozed_until`. Never `reminders.snoozed_until`.
- A snooze past the main due moment is accepted but then simply never fires (the
  strictly-before rule) — that is correct behavior, not an error; the main notification
  covers it. Note this in a comment.

## Keeping alert snoozes from going stale

An alert `snoozed_until` belongs to the *current occurrence*. If it lingered, the coalesce
would pin `effectiveFireAt()` to a past moment forever and the next occurrence's pre-alert
would never fire. Clear `snoozed_until` on every alert of a reminder when:

1. `Reminder::advanceOrComplete()` actually advances/completes the series (inside the
   `$advanced > 0` branch);
2. the user edits the reminder and `due_at` changes (`ReminderController@update`).

## CRUD & form

- `ReminderRequest`: `alerts` → `['nullable', 'array']`, `alerts.*` →
  `['integer', Rule::in(ReminderAlert::OFFSETS), 'distinct']`.
- `ReminderController@store/@update`: sync alerts — delete offsets no longer present,
  insert new ones, **leave existing rows untouched** (preserves their `snoozed_until`
  except when rule 2 above clears it).
- `ReminderPresenter::present()`: add `alerts: [{ id, offset_minutes, label ("1 hour
  before"), is_snoozed, snooze_label|null }]`. `formDefaults()`: add `alert_offsets:
  [{ value, label }]` for the picker. Update `resources/js/types/reminders.ts`
  (`Reminder`, `ReminderFormDefaults`) to match.
- `ReminderFormSheet.vue`: an "Alert me before" section — checkbox chips (follow the
  `repeat_weekdays[]` pattern, `name="alerts[]"`, mobile-friendly at 375px), pre-checked
  from the presented reminder on edit.
- List surfaces (`TodayReminderCard.vue`, `reminders/Index.vue` rows): a small lucide
  `Bell` glyph when the reminder has alerts, alongside the existing repeat glyph, with a
  `title` listing the labels. Nothing more.

## Acceptance criteria

Tests are class-based PHPUnit style (`test_snake_case` methods), matching the suite.

- **Feature (`SendDueReminders` / new `PreAlertDeliveryTest`)**, frozen time: fires at
  `due_at − offset`; not before; exactly once (second tick no-op); not when the main
  moment has already arrived; stale suppression (claimed, `sent_at` null); a snoozed
  alert re-fires at its snoozed moment; completing a recurring reminder clears alert
  snoozes and the next occurrence's pre-alert fires; shared reminders fan out to the
  household; quiet hours hold the pre-alert push (in-app now, push released after the
  window; superseded held alert pushes dropped); push payload has the distinct tag, both
  action URLs, and the `kind => pre_alert` database payload.
- **Feature (endpoints)**: web alert snooze — auth, policy (household member allowed on
  shared, stranger 403), preset and custom datetime, past refused, main
  `reminders.snoozed_until` untouched; signed route — valid signature snoozes, tampered
  params rejected, unknown preset 422.
- **Feature (CRUD)**: store/update sync semantics incl. preserving an existing alert's
  `snoozed_until` when untouched and clearing it when `due_at` changes; invalid offset
  rejected; presenter labels.
- **Dusk** (`tests/Browser/`, per CLAUDE.md — `PAO_DISABLE=1`, `.env.dusk.local` swap):
  create a reminder with "1 hour before" checked → bell glyph + form round-trips checked
  state on edit; unchecking removes it.
- `composer test` green (Pint + Larastan + Pest), full Dusk suite green.

## Open questions (resolved defaults — implement as stated)

1. Pre-alerts anchor to raw `due_at`, never to a main snooze. (Snooze presets are short;
   anchoring to snoozes would make "1 day before" nonsense.)
2. Pre-alert snooze presets reuse `SnoozePresets::KEYS`; only the notification button
   default differs (`10m`).
