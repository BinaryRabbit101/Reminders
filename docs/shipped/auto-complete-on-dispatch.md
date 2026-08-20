# Auto-Complete on Dispatch

**Status:** ✅ **Implemented — 2026-08-19**

## Close-out

**Deviations:** Two feature-test classes (`AutoCompleteDispatchTest`,
`AutoCompleteCrudTest`) rather than one, mirroring the pre-alerts split. Dusk asserts the
checkbox via `data-state` (`assertAttribute`) — reka-ui's `CheckboxRoot` renders a
`<button role="checkbox">` and hides the real input, so `assertChecked` isn't usable.
`auto_complete` is shaped in `ReminderRequest::recurrenceAttributes()` (which
`reminderAttributes()` spreads) — that's where the other repeat fields are shaped.

**Things later work must know:**
- This is the **one** exception to the 2026-08-07 recurrence amendment: for reminders
  with `auto_complete`, `SendDueReminders` advances the series at dispatch — hung off the
  **claim**, so a stale-suppressed occurrence advances too (after downtime the
  calculator's catch-up floor lands the series on the next occurrence still ahead).
  Default behavior is unchanged; `Reminder::complete()` remains the only other caller of
  `advanceOrComplete()`.
- No `ReminderCompletion` row is written on auto-advance — that log records user actions;
  the dispatch row is the record that the occurrence happened.
- `auto_complete` is normalized to `false` for one-off reminders in `ReminderRequest`,
  whatever was posted. Turning repeat off clears it.
- Snooze still composes: the snoozed moment fires, *then* the series advances from its
  own anchor. `advanceOrComplete()` clears `snoozed_until` and alert snoozes, so the next
  occurrence's pre-alerts re-arm naturally.
- Suite at close (combined with pre-alerts, shipped same day): 438 Pest tests / 1814
  assertions, 24 Dusk tests / 95 assertions, Larastan clean.

A per-reminder toggle, for **recurring reminders only**: when the reminder goes off, roll
straight to the next occurrence instead of parking in Overdue until the user completes it.

Read [../ARCHITECTURE.md](../ARCHITECTURE.md) and [recurrence.md](recurrence.md) first —
especially the 2026-08-07 amendment. That amendment established that dispatch never
advances a series; this feature makes that behavior **opt-in per reminder** rather than
reversing it. The default stays exactly as it is today.

Depends on: pre-alerts (touches the same `SendDueReminders` flow — implement after it).

## Model

- New column on `reminders`: `auto_complete` boolean default false. Add to the `Fillable`
  attribute, casts, and the model docblock.
- Meaningful only when `repeat_unit` is set. Persist `false` for one-off reminders no
  matter what was posted (normalize in `ReminderRequest::reminderAttributes()`, where the
  other repeat fields are already shaped — a one-off with `auto_complete = true` would be
  a reminder that silently ticks itself off, which nobody asked for).

## Engine behavior

In `SendDueReminders`, in the **main** due pass (never the pre-alert pass): after an
occurrence is claimed and then sent *or* stale-suppressed, when
`$reminder->auto_complete && $reminder->isRecurring()`, advance the series through the
existing seam:

```php
$reminder->advanceOrComplete($calculator, $occurredAt);
```

- Advance hangs off the **claim**, exactly like the pre-amendment design — a
  stale-suppressed occurrence advances too. For an auto-complete reminder, "parked in
  Overdue forever" is precisely what the user opted out of; after downtime the series
  catch-up floor in `RecurrenceCalculator::nextAfter()` already lands it on the next
  occurrence still ahead of now.
- `advanceOrComplete()` is already a compare-and-swap and already clears `snoozed_until`
  (and, after the pre-alerts spec, alert snoozes) — reuse it, do not reimplement.
- Do **not** write a `ReminderCompletion` row — that log records user actions; the
  dispatch row is the record that the occurrence happened. (Resolved default.)
- A finished series (`repeat_until` passed) gains `completed_at` via the same call —
  correct: the series is over.
- The existing comment block in `handle()` explaining "deliberately nothing here moves
  `due_at`" must be updated to name this one exception, and
  [recurrence.md](recurrence.md)'s amendment gets a one-line pointer to this spec at
  close-out time.
- Snooze still works: snoozing an auto-complete reminder moves its current occurrence
  (`snoozed_until`), the snoozed moment fires, and *then* it advances — the snooze is
  honored because the advance only happens at dispatch of the snoozed moment.

## Form & presentation

- `ReminderRequest`: `auto_complete` → `['nullable', 'boolean']` (+ the normalization
  above).
- `ReminderFormSheet.vue`: a checkbox inside the repeat section (only rendered when a
  repeat unit is selected), labeled "Complete automatically when it goes off", posting
  `auto_complete`. Follow the `is_shared` checkbox pattern.
- `ReminderPresenter::present()` + `formDefaults()` + `resources/js/types/reminders.ts`:
  carry `auto_complete: boolean` through.
- No list-surface glyph needed.

## Acceptance criteria

Class-based PHPUnit style, matching the suite.

- Feature (engine, frozen time): auto-complete recurring reminder advances `due_at` at
  dispatch (sent case AND stale-suppressed case) and does not appear in Overdue; a second
  tick is a no-op; without the toggle behavior is unchanged (existing tests keep passing
  untouched); auto-complete + snooze fires at the snoozed moment then advances from the
  series anchor; `repeat_until` exhaustion completes the series; no `ReminderCompletion`
  row is written; pre-alert for the *next* occurrence fires at `next due_at − offset`.
- Feature (CRUD): toggle round-trips through store/update for recurring reminders;
  normalized to false for one-offs; presenter carries it.
- Dusk: creating a repeating reminder shows the checkbox once a repeat unit is chosen,
  state round-trips on edit; the checkbox is absent for a one-off.
- `composer test` green, full Dusk suite green.
