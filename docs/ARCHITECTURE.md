# Architecture & Load-Bearing Decisions

**Status:** 📝 **Planning — 2026-08-03**

Read this before implementing any spec in [todo/](todo/). It records the constraints every
feature must respect.

## Stack (already scaffolded — do not re-scaffold)

This project is the current `laravel/vue-starter-kit`: Laravel ^13.17, Inertia ^3, Vue 3 +
TypeScript, Tailwind 4 (CSS-first, no `tailwind.config.js`), Vite 8, shadcn-vue
(`new-york-v4`, neutral, lucide icons — 21 UI components already vendored under
`resources/js/components/ui/`), reka-ui, Wayfinder typed routes, Fortify auth with
passkeys + 2FA, Pest 5, Larastan, Pint.

Commands: `composer dev` (server + queue + vite), `composer test` (pint + phpstan + pest),
`vendor/bin/pint --dirty`.

## Decisions

### 1. Time is the product — the timezone rule

The single most important lesson from sibling apps (StoryCampaign `routes/console.php`
learned this the hard way): **Laravel schedules and stores in UTC (`app.timezone`), and a
bare `dailyAt('07:30')` fires at 07:30 UTC, not local time.**

- All `datetime` columns store **UTC**. Non-negotiable.
- The app has one configurable display timezone: `REMINDERS_TIMEZONE` env →
  `config('reminders.timezone')`, defaulting to `America/Chicago` (confirmed by owner,
  2026-08-03). All user-facing date entry and display converts through it.
- Recurrence math ("every day at 9:00") is computed **in local time, then converted to
  UTC** — this is what makes DST transitions keep the reminder at 9:00 local.
- Any `Schedule::` entry that is time-of-day-meaningful must chain
  `->timezone(config('reminders.timezone'))`. The per-minute due-checker doesn't care,
  but daily digests do.

### 2. Delivery model

A per-minute scheduled command (`reminders:send-due`) queries reminders whose `due_at <=
now()` and not yet sent, and fires a Laravel Notification through
`[WebPushChannel::class, 'database']` — push to the phone, plus an in-app record. This is
the LittlePocketMeseum pattern (`app/Console/Commands/SendWishlistReminders.php`,
`app/Notifications/WishlistReminderNotification.php`). No queues needed at this scale;
schedule runs via `php artisan schedule:work` in production.

### 3. PWA pattern — copy, don't invent

StoryCampaign is the reference implementation. No Workbox, no `vite-plugin-pwa`: a
hand-written `public/sw.js` (~40 lines), `public/manifest.webmanifest`, VAPID key exposed
via a `<meta>` tag in `app.blade.php`, `resources/js/lib/push.ts` for subscribe flow, and
`laravel-notification-channels/webpush` ^11 on the backend. Push is a nicety — client-side
push code must never throw into the page.

### 4. HTTPS is a hard requirement for push

Service workers and the Push API require a secure context. The sibling apps are served
over plain HTTP on the mini-PC (`192.168.0.164:<port>`), which is fine for browsing but
**push will not work there**. Deployment must serve this app over HTTPS — the practical
option is Tailscale Serve on `minipc.jackal-hippocampus.ts.net` (Tailscale provisions the
cert). This is scoped in [todo/deployment-https.md](todo/deployment-https.md). During
local dev, `localhost` counts as a secure context, so everything works under
`composer dev`.

### 5. SQLite

`DB_CONNECTION=sqlite`, like every sibling app. Known caveats (from NorthernCall docs):
foreign keys can't be added after table creation and row locks are no-ops — enforce
integrity with unique indexes and compare-and-swap updates (`WHERE` guards on `UPDATE`),
not locking. Relevant here for the "send once" guarantee in the delivery engine.

### 6. Auth & app shape

Fortify defaults stay as scaffolded (multi-user, registration open — it's a personal app,
but keeping the stock shape matches every sibling). Route convention:
`Route::inertia('/', 'Welcome')->name('home')`, one `auth`+`verified` group, and
`Route::redirect('dashboard', '/today')->name('dashboard')` aliasing the starter-kit
dashboard to this app's real landing page, the Today view.

### 7. Wayfinder build trap

Run `php artisan optimize:clear` before `npm run build` — a stale route cache breaks the
Wayfinder-dependent Vite build (documented in NorthernCall's deployment notes).

## Data model overview

```
users                    (starter kit, + HasPushSubscriptions, + household_id)
households               name, invite_code — links the two accounts (shared-reminders spec)
push_subscriptions       (vendor migration from webpush package)
lists                    (optional grouping — see lists-and-tags spec)
reminders                title, notes, due_at (UTC), is_shared, recurrence fields,
                         snoozed_until, completed_at, list_id
reminder_dispatches      log of each send: reminder_id, occurrence due_at, sent_at
notifications            (Laravel database channel — in-app history)
```

Visibility rule (shared-reminders spec): private reminders are owner-only; shared
reminders are visible to — and pushed to — every member of the owner's household. The
rule lives in one query scope (`Reminder::visibleTo($user)`) that every surface (index,
Today, widget feed, delivery recipients) must go through.

Exact columns are defined per-spec; specs own their migrations.
