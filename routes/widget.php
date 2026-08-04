<?php

use App\Http\Controllers\WidgetFeedController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Widget Feed Routes
|--------------------------------------------------------------------------
|
| The read-only JSON the iPhone home-screen widget fetches. Registered in
| bootstrap/app.php *outside* the web middleware group: Scriptable carries no
| session and no CSRF token, so the per-user `?token=` is the whole
| authorization — the same trade the notification-action routes make with
| their signature.
|
| The `api/` prefix is not decoration: bootstrap/app.php renders exceptions on
| `api/*` as JSON, which is what turns a refused token into a JSON 403 rather
| than an HTML error page a widget cannot read.
|
| See App\Http\Controllers\WidgetFeedController.
|
*/

Route::get('today', [WidgetFeedController::class, 'today'])->name('today');
