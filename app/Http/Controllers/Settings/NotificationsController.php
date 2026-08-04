<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationsController extends Controller
{
    /**
     * Show the notification settings page.
     *
     * Whether *this* device is subscribed is a browser-side fact, so the page
     * only needs to know that push is configured at all and how many devices
     * the account has registered.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Notifications', [
            'pushConfigured' => (bool) config('webpush.vapid.public_key'),
            'subscriptionCount' => $request->user()->pushSubscriptions()->count(),
        ]);
    }
}
