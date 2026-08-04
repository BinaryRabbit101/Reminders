<?php

use App\Http\Controllers\HistoryController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReminderActionController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\ReminderListController;
use App\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/today')->name('dashboard');

    Route::get('today', [TodayController::class, 'index'])->name('today');

    // What was already sent. Visiting marks it read (notification-history).
    Route::get('history', [HistoryController::class, 'index'])->name('history');

    Route::get('reminders', [ReminderController::class, 'index'])->name('reminders.index');
    Route::post('reminders', [ReminderController::class, 'store'])->name('reminders.store');
    Route::put('reminders/{reminder}', [ReminderController::class, 'update'])->name('reminders.update');
    Route::delete('reminders/{reminder}', [ReminderController::class, 'destroy'])->name('reminders.destroy');

    // Acting on a reminder rather than editing it. The signed twins of the
    // first two — tapped from a push notification, no session — live in
    // routes/notification-actions.php.
    Route::post('reminders/{reminder}/complete', [ReminderActionController::class, 'complete'])->name('reminders.complete');
    Route::post('reminders/{reminder}/snooze', [ReminderActionController::class, 'snooze'])->name('reminders.snooze');
    Route::post('reminders/{reminder}/restore', [ReminderActionController::class, 'restore'])->name('reminders.restore');

    // Lists are personal, so every route here is scoped to the owner: the
    // index reads `$user->lists()` and the rest go through ReminderListPolicy.
    Route::get('lists', [ReminderListController::class, 'index'])->name('lists.index');
    Route::post('lists', [ReminderListController::class, 'store'])->name('lists.store');
    Route::put('lists/{list}', [ReminderListController::class, 'update'])->name('lists.update');
    Route::delete('lists/{list}', [ReminderListController::class, 'destroy'])->name('lists.destroy');

    // Filing an *existing* reminder into a list, from the lists page's
    // picker — the counterpart to un-filing, which happens by picking
    // "No list" in the reminder's own edit sheet.
    Route::put('lists/{list}/reminders/{reminder}', [ReminderListController::class, 'assign'])->name('lists.reminders.assign');

    Route::post('push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push.store');
    Route::delete('push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');
});

require __DIR__.'/settings.php';
