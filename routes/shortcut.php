<?php

use App\Http\Controllers\ShortcutReminderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shortcut Routes
|--------------------------------------------------------------------------
|
| The write half of the phone's API: what the iOS Shortcut posts to when you
| ask Siri to add a reminder. Registered in bootstrap/app.php *outside* the
| web middleware group for the reason routes/widget.php is — the Shortcuts app
| carries no session and no CSRF token, so the per-user token is the whole
| authorization (App\Http\Middleware\ResolveShortcutToken).
|
| The `api/` prefix is not decoration here either: bootstrap/app.php renders
| exceptions on `api/*` as JSON, which is what lets the Shortcut read a
| refusal or a validation failure back to you instead of a page of HTML.
|
| See App\Http\Controllers\ShortcutReminderController.
|
*/

Route::post('reminders', [ShortcutReminderController::class, 'store'])->name('reminders.store');
