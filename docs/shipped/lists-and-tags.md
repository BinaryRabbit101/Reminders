# Lists

**Status:** ✅ **Implemented — 2026-08-03**

## Close-out

**Deviations:** Model is `ReminderList` (`List` is a PHP reserved word), table stays
`lists`. Color tokens are stored (`'emerald'`, palette enum in `App\Support\ListColor`)
with **hex emitted server-side** by `ReminderPresenter` as `color_hex` applied as inline
style — a runtime `bg-${token}-500` class can never work under Tailwind 4's
source-scanning. `destroy` nulls `list_id` explicitly in addition to the FK's
`nullOnDelete` (SQLite FK enforcement may be off).

**Things later work must know:**
- **Household rule (post-spec decision):** lists are personal. `present()` emits
  `list`/`list_id` only when the viewer owns the reminder; the partner sees no badge.
  The push title prefix ("Errands — pick up parcel") uses the owner's list name for all
  recipients (`ReminderDueNotification::pushTitle()`).
- `ReminderRequest::listAttributes()` omits the `list_id` key entirely when the poster
  isn't the owner — otherwise a partner's edit would silently un-file the owner's
  reminder (regression-tested). `list_id` validation is scoped
  `Rule::exists(...)->where('user_id', $poster)`.
- Filter is a query param on reminders.index (`active_list_id` prop, snake_case by
  design). The nav "Lists" entries in AppSidebar/AppHeader were added by the
  orchestrator post-merge (lucide `Tags` icon).
- Suite at close (post-consolidation): 247 tests / 1028 assertions, 0 skipped.

Lightweight grouping of reminders into lists ("Errands", "Work", "Meds"). Deliberately
minimal — this is a personal app, not a project manager.

## Model

- `lists` table: `user_id` FK, `name`, `color` (string — a small fixed palette of
  Tailwind color tokens), timestamps. Unique `['user_id', 'name']`.
- `reminders.list_id` nullable FK (new migration; SQLite — add via new table column on
  creation-order-safe migration, FK constraint included at add time works for new column).
- No tags table — lists only. (If tags ever feel needed, that's a new spec; record the
  decision here if discussed away.)

## UI

- List management inline in settings or a small `/lists` page: create, rename, recolor,
  delete (delete prompts; reminders become list-less, not deleted).
- Reminder form (sheet) gains a list `select`.
- Today view and reminders index: colored dot/badge per row; a filter chip row to show a
  single list.

## Acceptance criteria

- CRUD lists, assign/unassign reminders, filter by list; deleting a list orphans (not
  deletes) its reminders. Policy: owner-only. Pest coverage for all of the above.
- 375px layout holds with long list names.

## Open questions

1. Should the push notification mention the list name? (Assume yes if present: "Errands —
   pick up parcel".)
