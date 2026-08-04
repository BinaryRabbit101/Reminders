<?php

use App\Http\Controllers\NotificationActionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notification Action Routes
|--------------------------------------------------------------------------
|
| The endpoints behind the buttons on a push notification. Registered in
| bootstrap/app.php *outside* the web middleware group: a service worker
| fetch carries no CSRF token and no dependable session, so these routes get
| `signed` (the signature is the authorization) plus explicit binding, and
| nothing else. See App\Http\Controllers\NotificationActionController.
|
| They deliberately mirror the in-app paths under a prefix of their own, so
| the two families can never be confused for one another in a route list.
|
*/

Route::post('reminders/{reminder}/complete', [NotificationActionController::class, 'complete'])
    ->name('complete');

Route::post('reminders/{reminder}/snooze', [NotificationActionController::class, 'snooze'])
    ->name('snooze');
