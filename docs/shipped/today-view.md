# Today View

**Status:** ✅ **Implemented — 2026-08-03**

## Close-out

**Deviations:** Date formatting was extracted into `app\Support\ReminderPresenter.php`
rather than duplicated — it is now THE single UTC→local presentation point (supersedes
the crud close-out's "in `ReminderController::index`"). CRUD redirects changed to
`back(fallback: route('reminders.index'))` so the shared form sheet returns to whichever
page opened it. Rows open the edit sheet in place.

**Things later work must know:**
- Bucketing lives in `app\Support\TodayBoard.php`: boundaries computed on the *local*
  calendar (`config('reminders.timezone')`), converted to UTC only for SQL. Snoozed
  reminders bucket by `coalesce(snoozed_until, due_at)` — that's what keeps a
  future-snoozed reminder out of Overdue.
- DST is regression-tested for 2026-03-08 (spring-forward) and 2026-11-01 (fall-back) in
  `tests\Feature\TodayBoardTest.php` — extend those tests, don't delete them.
- The client receives only pre-formatted strings (`due_label`, day headings) — never add
  timezone math to Vue.
- Upcoming window is 7 local days; `/today` is the dashboard redirect target and the PWA
  `start_url`.
- Wayfinder regeneration **must** use `--with-form` (repeat offender — broke twice
  during parallel work).

The app's landing page: what needs attention now. This is the screen that opens when the
PWA icon is tapped.

## Behavior

Three sections, in order, all user-scoped and pending-only:

1. **Overdue** — `due_at` in the past, not completed, not snoozed into the future.
   Visually distinct (destructive/red accent).
2. **Today** — due later today (local timezone day boundaries — compute the day window in
   `config('reminders.timezone')`, convert to UTC for the query).
3. **Upcoming** — the next 7 days, grouped by day with local-format headers ("Tomorrow",
   "Wed, Aug 5").

Empty state when nothing is pending ("All clear 🎉" style, keep it simple). Each row links
to edit; completing/snoozing from here arrives with the snooze-and-complete spec — leave
affordance space but don't build the actions.

## Implementation

- `TodayController@index` → `resources/js/pages/Today.vue` at `/today`; repoint
  `Route::redirect('dashboard', '/today')->name('dashboard')`.
- Grouping/windowing logic lives in the controller (or a small query service), tested in
  isolation with Pest — especially the local-day-boundary math around midnight and DST.
- PWA `start_url` will point at `/today` (pwa-shell spec) — keep the route stable.

## Reference implementations

- Landing-page aliasing convention: PasswordVault (`/vault`), StoryCampaign
  (`/campaigns`) — `Route::redirect('dashboard', ...)` in their `routes/web.php`.

## Acceptance criteria

- A reminder due 23:30 local yesterday shows in Overdue, not Today, even when UTC date
  differs from local date.
- Sections render correctly at 375px.
- Pest tests cover the three-bucket classification incl. a DST-transition date.

## Open questions

1. Should Upcoming be 7 days or "everything future"? (Spec assumes 7 days + the rest
   reachable via the reminders index page.)
