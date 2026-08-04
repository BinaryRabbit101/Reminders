# Reminders CRUD

**Status:** ✅ **Implemented — 2026-08-03**

## Close-out

**Deviations:** Native `<input type="date">`/`<input type="time">` instead of a shadcn
`select` (real mobile pickers at 375px); notes field is a hand-styled native `<textarea>`
(kit has no vendored one, classes mirror `Input.vue`); tests are PHPUnit class-style to
match the existing repo convention (`tests/Pest.php` has no `uses()` bindings); the
policy defines only `update`/`delete` — no show page, and `store` is scoped by
construction via `$request->user()->reminders()->create(...)`.

**Things later work must know:**
- Local→UTC conversion happens in exactly one place:
  `ReminderRequest::reminderAttributes()` (`Carbon::parse(..., config('reminders.timezone'))->utc()`).
  UTC→local happens only in `ReminderController::index()`, which emits pre-formatted
  `due_date`/`due_time`/`due_label` — **the client never does timezone math**. Keep it that way.
- `config/reminders.php` exists: `timezone` (env `REMINDERS_TIMEZONE`, default
  `America/Chicago`) and `default_time` (`09:00`).
- `php artisan wayfinder:generate` must be run **`--with-form`** — without it the form
  variants that `vite.config.ts` expects are silently stripped, breaking existing pages.
- `npm run build` is required after adding any Inertia page — feature tests 500 on a
  stale Vite manifest.
- `Route::redirect('dashboard', '/reminders')` is temporary; today-view repoints it to `/today`.
- Fixed pre-existing `.env` typo (`APP_URL` had a duplicated port); `REMINDERS_TIMEZONE`
  added to both env files. Test suite at close: 50/50 passing, phpstan level 7 clean.

The core domain object: create, edit, and delete reminders. Everything else in the app
hangs off this model.

## Behavior

- A reminder has a **title** (required), optional **notes**, and a **due date + time**
  entered in the app's local timezone (see ARCHITECTURE.md §1) and stored as UTC `due_at`.
- Creating should be fast and phone-friendly: a single form reachable from a prominent
  "+" button, with sensible defaults (due date = today, time = next round hour or the
  configurable default time).
- Editing reuses the same form. Deleting asks for confirmation (shadcn `dialog`).
- A reminder belongs to the authenticated user. All queries are user-scoped.

## Data model

`reminders` migration (this spec owns it; later specs add columns via their own migrations):

| Column | Type | Notes |
|---|---|---|
| id, user_id | — | `foreignId('user_id')->constrained()->cascadeOnDelete()` — FK now, not later (SQLite) |
| title | string | required |
| notes | text nullable | |
| due_at | datetime | UTC |
| completed_at | datetime nullable | set by snooze-and-complete spec, but include the column now |
| snoozed_until | datetime nullable | ditto |
| timestamps | | |

Index `['user_id', 'due_at']` — every list view sorts on it.

## Backend

- `Reminder` model: PHP-attribute config (`#[Fillable]`) like the `User` model, `casts`
  for the datetimes, `user()` relation, and scopes `pending()` (`completed_at` null),
  `due()` (used later by the delivery engine).
- `ReminderController` resource routes (`index/store/update/destroy`, no separate show
  page) inside the `auth`+`verified` group, with a `ReminderPolicy` (owner-only) and
  FormRequest validation. `due_at` arrives as local wall-time + is converted to UTC in
  the FormRequest or a cast — pick one place, document it in the close-out.
- Factory + seeder with a handful of demo reminders for local dev.

## Frontend

- `resources/js/pages/reminders/Index.vue` — flat list for now (the Today view spec
  replaces it as landing page), each row: title, human relative due time ("in 2 h",
  "yesterday"), edit/delete actions.
- Create/edit as a shadcn `sheet` (bottom sheet works well on mobile) rather than a
  separate page. Use vendored `input`, `label`, `button`, `select` components; `sonner`
  toast on save.
- Route alias per convention: `Route::redirect('dashboard', '/reminders')` for now
  (today-view spec repoints it to `/today`).

## Reference implementations

- Model/controller/policy shape: `C:\Users\binar\Documents\websites\StoryCampaign` (any
  resource controller) — match its FormRequest + policy idioms.
- Starter-kit page/layout conventions: existing `resources/js/pages/Dashboard.vue` and
  `layouts/` in this repo.

## Acceptance criteria

- Can create, edit, delete reminders from the browser at 375px width without layout breakage.
- `due_at` round-trips correctly: enter 09:00 local → stored UTC → displayed 09:00 local.
- Pest feature tests: CRUD happy paths, authorization (user B cannot touch user A's
  reminder), validation errors. `composer test` green.

## Out of scope

Recurrence, snooze/complete UI, lists — later specs.

## Open questions

1. ~~Confirm the default local timezone~~ — **Answered 2026-08-03: `America/Chicago`.**
2. Default reminder time when only a date is picked — assume 09:00 until the owner says
   otherwise.
