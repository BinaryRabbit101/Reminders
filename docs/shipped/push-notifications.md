# Push Notifications (subscribe plumbing)

**Status:** ✅ **Implemented — 2026-08-03** (browser-side delivery verification remains manual — see below)

## Close-out

**Deviations:** Settings page route lives in `routes/settings.php` (file's convention),
push subscription routes in `web.php`. Push state logic is shared via
`resources/js/composables/usePush.ts`. `enablePush`/`disablePush` await their Inertia
visit (`onFinish` promise) to avoid a stale device-count race. The `/today` prompt
(`EnablePushCard.vue`) was wired into `Today.vue` by the orchestrator after both parallel
agents finished; its dismissal persists per-device in
`localStorage['reminders:push-prompt-dismissed']`.

**Things later work must know:**
- `lib/push.ts` does **not** register the service worker (boot registration in
  `lib/serviceWorker.ts` owns that). It uses `getRegistration('/')` then
  `navigator.serviceWorker.ready` raced against a 5 s timeout, and every path swallows
  errors into a returned state.
- VAPID keys are generated and live in `.env` (dev keys — production must generate its
  own). Windows recipe: `$env:OPENSSL_CONF = "C:\Program Files\Git\usr\ssl\openssl.cnf"`
  then `php artisan webpush:vapid`.
- `php artisan push:test {user?}` sends a WebPush-only test notification; fails loudly
  when keys/user/subscriptions are missing.
- **⚠ Dependency trap (pre-existing, still open):** `composer.json` requires
  `pestphp/pest ^5.0`, but Pest 5 needs PHP ≥ 8.4.1 and this machine runs **PHP 8.4.0**;
  Pest was never actually installed — the suite runs on PHPUnit 12. Consequently
  `composer install`/`composer require` **fail** on this box (the webpush install worked
  only by temporarily swapping pest→phpunit in composer.json and restoring after).
  Proper fix: upgrade PHP to ≥ 8.4.1, or replace the pest requirement with
  `phpunit/phpunit ^12.5`. Until then, avoid composer dependency changes.
- `brick/math` was downgraded 0.18.0 → 0.17.2 (webpush's jwt-library needs ^0.17).
- **Manual verification still owed:** enable push in a real browser at
  `/settings/notifications`, then `php artisan push:test` → notification should appear
  and click-through should land on `/today`.

Everything needed for the phone to receive web pushes — VAPID keys, the subscribe flow,
subscription storage, and a test command. The *sending of actual reminders* is the
delivery-engine spec; this spec ends at "test push arrives on the phone".

## Backend (pattern: StoryCampaign + LittlePocketMeseum)

1. `composer require laravel-notification-channels/webpush:^11.0`, publish its
   `push_subscriptions` migration (StoryCampaign has the vendor-published copy:
   `database\migrations\2026_07_22_232653_create_push_subscriptions_table.php`). Do
   **not** publish `config/webpush.php` — StoryCampaign reads
   `config('webpush.vapid.public_key')` from the vendor default; match that.
2. `User` model: add `use NotificationChannels\WebPush\HasPushSubscriptions;`.
3. `.env.example` keys: `VAPID_PUBLIC_KEY=`, `VAPID_PRIVATE_KEY=`,
   `VAPID_SUBJECT=mailto:binaryrabbit101@gmail.com`. Generate real values with
   `php artisan webpush:vapid` — **Windows gotcha** (from StoryCampaign's CLAUDE.md):
   set `OPENSSL_CONF` to a real `openssl.cnf` (Git's install has one) or generation fails.
4. `PushSubscriptionController` copied from
   `C:\Users\binar\Documents\websites\StoryCampaign\app\Http\Controllers\PushSubscriptionController.php`:
   `store` → `updatePushSubscription()`, `destroy` → `deletePushSubscription()`, return
   `back()`. Routes in the auth group: `Route::post('push/subscriptions')->name('push.store')`,
   `Route::delete('push/subscriptions')->name('push.destroy')`.
5. `app/Console/Commands/SendTestPush.php` — a `push:test {user?}` command, copied from
   LittlePocketMeseum's `SendTestPush.php`. This is the acceptance test.

## Frontend

- `resources/js/lib/push.ts` copied from StoryCampaign: `enablePush()` reads the VAPID
  key from the `<meta name="vapid-public-key">` tag, registers `/sw.js`, requests
  permission, subscribes, POSTs via
  `router.post(..., { preserveState: true, preserveScroll: true, only: [] })`.
  **Swallow all errors** — "push is a nicety; never let it break the page."
- An "Enable notifications on this device" button — put it on the settings page area
  (`resources/js/pages/settings/`) plus a dismissible prompt on `/today` when permission
  is `default` and no subscription exists. Show subscribed state and a disable action.

## Acceptance criteria

- Enable-flow works in local Chrome: permission prompt → subscription row appears in
  `push_subscriptions`.
- `php artisan push:test` delivers a visible notification to the subscribed browser, and
  clicking it opens/focuses the app at `/today`.
- Disabling removes the subscription. Pest tests for store/destroy endpoints.

## Out of scope

Reminder-triggered sends (delivery-engine), notification action buttons
(snooze-and-complete), production HTTPS (deployment-https).
