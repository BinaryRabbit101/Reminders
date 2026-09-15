# Calendar View

**Status:** ✅ **Implemented — 2026-09-15**

## Close-out

**Deviations:** Built as a **view switch on `/reminders`**, not a page of its own. The ask
was "show a calendar alongside the list", and a second route would have meant a second
place for the list chips, the completed toggle and the create sheet to drift out of sync.
`?view=calendar` rides in the URL beside the existing filters, so the whole page is
linkable and reload-safe, and both views are drawn from **one query**.

Recurring reminders are **projected** across the visible grid (owner's decision,
2026-09-15). A repeating reminder stores only the occurrence it currently sits on, so a
plain grid of `due_at` would have shown a weekly chore once a month and the calendar
would have looked empty — which is the version of this feature not worth building.

**Things later work must know:**

- Cells are **local days** (`ReminderCalendar`), the same rule `TodayBoard` exists to
  enforce: a reminder due 23:30 local belongs to that local day even though its UTC date
  has already rolled. `tests/Feature/ReminderCalendarTest.php` pins this with fixtures
  whose UTC date deliberately disagrees with their local one — don't "simplify" them.
- The stored occurrence is placed at **`effectiveDueAt()`** (a snooze moves the
  occurrence, and a calendar's job is to say when something will actually go off), but
  projections step from **`due_at`** — a snooze moves one occurrence, never the schedule.
  This is the same split `Reminder::advanceOrComplete()` documents.
- A **projection is not a row.** It carries `is_projected: true`, is never completed,
  snoozed or overdue whatever the stored occurrence is doing, and the client draws it
  faintly with no tick, snooze or delete — there is nothing there to act on yet. Clicking
  one opens the *series* for editing.
- Projection starts with `RecurrenceCalculator::nextAfter()` from the window's start, not
  by stepping from the series' current position: browsing a year ahead is otherwise more
  steps than any cap allows. `ReminderCalendar::MAX_PROJECTIONS` (60) bounds what one
  reminder may contribute to one grid.
- Recurrence stepping runs on the **owner's** clock (`RecurrenceCalculator::for($reminder->user)`)
  while the grid is drawn on the **viewer's** — a household member looking at a shared
  9:00 chore sees it where its owner's 9:00 falls.
- Every "is it past?" question on a grid is answered by the **one `$now`** passed into
  `for()`, never by `isFuture()` — that is what makes the whole thing testable without
  `travelTo`.
- The calendar payload is built **only when it is being looked at** (`view=calendar`);
  the list view gets `calendar: null`. Projecting recurrences is real work the list has
  no use for.
- The grid is a summary and the **day panel underneath is the working surface** — it
  reuses `TodayReminderCard`, so a reminder is acted on the same way it is on `/today`.
  That split is what keeps a month usable at 375px, where a cell is dots and a count.
- Entries cache nothing about a row beyond a title, a time, a colour and some flags; the
  page carries every row in full and the client joins on `reminder_id`.

## Behavior

A view switch at the top of `/reminders`, beside the filters:

- **List** — unchanged, soonest first.
- **Calendar** — a month grid of the same rows, whole weeks Sunday → Saturday, so the
  leading and trailing cells belong to the neighbouring months (`in_month: false`).
  Reminders that fall in them are still drawn: the week is on screen, and hiding a Monday
  chore because the month happened to start on Wednesday would be a lie about that week.

Both views obey the same two filters — the list chips and **Show completed** — because
both are built from one query. Switching views keeps the filters; switching back to the
list drops the month, which would otherwise be a stale parameter waiting to surprise
whoever returns to the calendar.

### The grid

- Each cell shows the day number (today's is a filled pill) and up to three entries —
  time + title on `sm` and up, coloured dots at phone width — then "+N more".
- An entry's dot is the **viewer's own** list colour, like every other surface; unfiled
  rows fall back to a neutral dot, overdue to the destructive one.
- Completed entries are struck through (only visible with the toggle on), overdue ones
  are red, projections are faint.

### The day panel

- Tapping a cell opens that day underneath the grid; it opens on **today** when today is
  on the grid, otherwise on the first of the month.
- Stored occurrences render as `TodayReminderCard` — complete, snooze, edit, delete.
- Projections render as a dashed, muted row carrying the repeat label ("Every day"); the
  only thing they do is open the series.
- **Add** on the panel opens the create sheet with that day's date filled in. The *time*
  stays the server's default: a day cell says nothing about what time of day was meant.

### URL

`/reminders?view=calendar&month=YYYY-MM`, alongside `list=` and `show_completed=`. A
month that does not parse falls back to the viewer's current month rather than 404-ing —
the same forgiveness the list filter shows a list id that does not resolve.

## Acceptance criteria

- [x] A view switch on `/reminders`; the list view is untouched and still the default.
- [x] A reminder appears in the cell of its **local** day, not its UTC one.
- [x] A repeating reminder appears on every occurrence its rule produces inside the
      visible grid, stopping at `repeat_until`.
- [x] A projection can't be completed, snoozed or deleted, and never inherits the stored
      occurrence's snooze or completion.
- [x] A daily series projects correctly into a month a year away.
- [x] The list chips and Show completed narrow the calendar exactly as they narrow the
      list; month and view survive a reload and are linkable.
- [x] Month arrows and a Today jump, all carrying the filters.
- [x] Tapping a day opens it below the grid with full actions; **Add** opens the sheet on
      that day.
- [x] No horizontal overflow at 375px (Dusk).
- [x] Suite at close: 530 Pest tests / 2183 assertions green; Dusk 43 tests, the five new
      calendar ones green every run. Pint clean. Larastan clean apart from one
      pre-existing `ReminderEventsFeed` list variance that predates this work.
      `AuthenticationTest > user can logout` fails in *some* full Dusk runs and passes on
      its own — the sidebar-dropdown flakiness CLAUDE.md warns about, reproduced with
      this feature's tests removed, so it is not this work's.
