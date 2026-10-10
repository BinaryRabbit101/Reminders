<?php

use App\Http\Controllers\PushApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Push API Routes
|--------------------------------------------------------------------------
|
| Reminders as the estate push service (HUB SHAPE.md move 3): other sites and
| box scripts send a push to the household's phones over HTTP, without VAPID
| keys of their own. Registered in bootstrap/app.php outside the web group —
| no session, no CSRF — behind the estate-standard token check
| (App\Http\Middleware\RequireApiToken: `X-Api-Token` or Bearer) and a
| throttle.
|
| See App\Http\Controllers\PushApiController and docs/shipped/push-api.md.
|
*/

Route::post('push', [PushApiController::class, 'send'])->name('push.send');
Route::get('push/recipients', [PushApiController::class, 'recipients'])->name('push.recipients');
