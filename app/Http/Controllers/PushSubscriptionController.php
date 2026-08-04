<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /**
     * Store (or refresh) the push subscription for the calling device.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
        );

        return back();
    }

    /**
     * Forget the push subscription for the calling device.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate(['endpoint' => ['required', 'string']]);

        $request->user()->deletePushSubscription($validated['endpoint']);

        return back();
    }
}
