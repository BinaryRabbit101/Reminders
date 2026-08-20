# Recurrence

**Status:** ✅ **Implemented — 2026-08-03**, amended 2026-08-07

## Amendment — 2026-08-07

The delivery engine no longer advances `due_at`. Originally it did — "advance hangs
off the claim, not the send" — on the reasoning that leaving `due_at` alone would park
an uncompleted occurrence in Overdue forever. That turned out to be wrong: a reminder
must not roll to its next repeat until the user actually completes it. Being pushed
(or stale-suppressed) is no longer treated as being handled.

`Reminder::advanceOrComplete()` is unchanged and is still the only place the "next
occurrence, or completed" decision is made — only its caller changed.
`SendDueReminders` now just claims and sends (or stale-suppresses) an occurrence;
`Reminder::complete()` is the sole caller that advances the series. An uncompleted
recurring reminder now sits in Overdue indefinitely, exactly like a one-off would,
however many ticks or repeat cycles pass. Every "Things later work must know" bullet
and the "Coordination with delivery engine" section below describes the superseded
design; read them as history, not current behavior.

(2026-08-19: [auto-complete-on-dispatch.md](auto-complete-on-dispatch.md) made
advance-at-dispatch available again as an explicit per-reminder opt-in — the default
behavior described by this amendment is unchanged.)

## Close-out

**Deviations:** Extra column `repeat_anchor_day` — "monthly on the 31st" is not
derivable from a clamped `due_at` (Feb 28 is ambiguous), so the intended day is stored
at entry time and never touched by advancing. `RecurrenceCalculator::nextAfter()`
catch-up floors to the next occurrence still ahead of now (capped 1000 steps) instead of
crawling one step per tick. Native `<select>` for repeat pickers (matches the
mobile-native-controls precedent). `RecurrenceRule` value object keeps the calculator
Eloquent-free.

**Things later work must know (superseded 2026-08-07 — see amendment above):**
- ~~Advance hangs off the **claim**, not the send — stale-suppressed occurrences still
  advance. Order in `SendDueReminders`: claim → send-or-suppress → advance.~~ Advance now
  hangs off completion only; `SendDueReminders` stops at claim → send-or-suppress.
- The CAS is `UPDATE ... SET due_at = <next>, snoozed_until = null WHERE id = ? AND
  due_at = <previous>` — dispatch key is the *effective* occurrence, CAS guard is raw
  `due_at`. A snooze moves one occurrence; the series schedule always resumes from its
  own anchor (daily 09:00 snoozed to 12:59 fires at 12:59, returns tomorrow 09:00).
- **For the complete endpoint** (snooze-and-complete):
  `Reminder::advanceOrComplete(RecurrenceCalculator $calc, ?DateTimeInterface $occurredAt = null): bool`
  — returns false only for one-off reminders (untouched); caller then sets
  `completed_at` itself. `$occurredAt` defaults to `effectiveDueAt()`.
- Past `repeat_until` (inclusive, compared on the local calendar) the same CAS sets
  `completed_at` and leaves `due_at` on the final occurrence.
- DST both directions, Feb-29 yearly, 31st anchor climb-back are all unit-tested in
  `tests\Unit\RecurrenceCalculatorTest.php` — extend, don't weaken.
- Suite at close: 179 tests / 690 assertions. Manual 375px browser check of the new
  form controls still owed.

Repeating reminders: "every day at 9:00", "every Monday", "monthly on the 15th",
"every 3 days".

## Model

Keep it simple — structured columns, not RRULE strings:

| Column (on `reminders`) | Type | Meaning |
|---|---|---|
| repeat_unit | string nullable | `null` (one-off), `day`, `week`, `month`, `year` |
| repeat_interval | unsignedSmallInt default 1 | every N units |
| repeat_weekdays | json nullable | for `week`: [1,3,5] ISO weekday numbers |
| repeat_until | date nullable | optional end date (local) |

`due_at` always holds the **current occurrence** (UTC) until the user completes it — the
delivery engine dispatching it does not move it (2026-08-07 amendment). Completing a
recurring reminder (snooze-and-complete spec) advances `due_at` to the next occurrence
rather than setting `completed_at`; past occurrences live in `reminder_dispatches`.

## The timezone rule applies here hardest

Next-occurrence math must run in local time (`config('reminders.timezone')`) and convert
back to UTC — "every day at 9:00" stays 9:00 local across DST transitions
(ARCHITECTURE.md §1). Implement as a small pure class
(`app/Support/RecurrenceCalculator.php` or similar) so it's trivially unit-testable.
Monthly edge case: "monthly on the 31st" → clamp to last day of shorter months.

## UI

Extend the create/edit sheet from reminders-crud: a repeat `select` (None / Daily /
Weekly / Monthly / Yearly / Custom…), weekday toggle chips when Weekly, interval stepper
under Custom, optional end date. Recurring reminders show a repeat glyph (lucide
`repeat`) in lists and the Today view.

## Coordination with delivery engine

Superseded by the 2026-08-07 amendment: the dispatch flow is insert dispatch row → send
(or stale-suppress), full stop — it never touches `due_at`. The guarded compare-and-swap
(`UPDATE ... WHERE due_at = <the occurrence just handled>` — SQLite-safe) only runs from
`Reminder::complete()`. When `repeat_until` is passed, completing the final occurrence
marks it completed instead of advancing; an expired series the user never completes just
stays overdue, same as any other uncompleted reminder.

## Acceptance criteria

- Pest unit tests on the calculator: daily across a DST boundary (due stays 9:00 local),
  weekly multi-day, monthly 31st→Feb, interval > 1, until-date termination.
- Feature test: dispatching a recurring reminder never advances `due_at` (superseded —
  see amendment); completing one advances `due_at` exactly once even if attempted twice.
- UI can create each recurrence type at 375px.

## Open questions

1. Is "every N hours" / time-of-day-multiple recurrence needed (e.g. medication)? Spec
   currently starts at daily granularity.
