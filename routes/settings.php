<?php

use App\Http\Controllers\Settings\HouseholdController;
use App\Http\Controllers\Settings\NotificationsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\ReminderSettingsController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/notifications', [NotificationsController::class, 'edit'])->name('notifications.edit');

    // Account-level delivery preferences — timezone, default time, quiet
    // hours. Named apart from the `reminders.*` resource routes on purpose:
    // these are settings *about* reminders, not routes to them, and sharing
    // the prefix would fold them into the same generated Wayfinder module.
    Route::get('settings/reminders', [ReminderSettingsController::class, 'edit'])->name('reminder-settings.edit');
    Route::patch('settings/reminders', [ReminderSettingsController::class, 'update'])->name('reminder-settings.update');

    // Mint (or roll) the bearer token behind the home-screen widget's feed.
    // A POST rather than part of the settings form: it is an action with a
    // side effect somebody has to mean, not a preference being saved.
    Route::post('settings/reminders/widget-token', [ReminderSettingsController::class, 'regenerateWidgetToken'])
        ->name('reminder-settings.widget-token');

    Route::get('settings/household', [HouseholdController::class, 'edit'])->name('household.edit');
    Route::post('settings/household', [HouseholdController::class, 'store'])->name('household.store');
    Route::post('settings/household/join', [HouseholdController::class, 'join'])->name('household.join');
    Route::post('settings/household/invite-code', [HouseholdController::class, 'regenerate'])->name('household.regenerate');
    Route::delete('settings/household', [HouseholdController::class, 'destroy'])->name('household.leave');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
