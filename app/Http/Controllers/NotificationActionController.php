<?php

namespace App\Http\Controllers;

use App\Models\Reminder;
use App\Support\RecurrenceCalculator;
use App\Support\SnoozePresets;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The two buttons on the push notification itself, answered without opening
 * the app.
 *
 * These routes live outside the session middleware on purpose. A service
 * worker fetch has no CSRF token and no guarantee of a logged-in session —
 * the notification may be tapped days later, on a device whose session has
 * long since expired. So the **signature is the authorization**: the URLs are
 * minted by `ReminderDueNotification` with `URL::temporarySignedRoute()` and
 * handed to the browser inside the encrypted push payload, and the `signed`
 * middleware rejects anything altered or expired before this class is
 * reached. There is no `$request->user()` here and there must not be one.
 *
 * That also means the reminder id is not a secret worth guarding: knowing it
 * buys nothing without the matching signature.
 *
 * Whose clock? There is no acting user to ask, and the same signed URL rides
 * in every household member's copy of the push, so both actions here run on
 * the reminder **owner's** timezone — the same choice the scheduler makes when
 * it advances a series with nobody logged in. Nothing observable turns on it
 * today (the button snoozes by "1h", which is the same hour everywhere), but
 * the rule needs to be one rule.
 *
 * Responses are `204 No Content`. Nobody renders them — `sw.js` fires the
 * request and drops the result on the floor.
 */
class NotificationActionController extends Controller
{
    /**
     * Complete the reminder (or advance it, when it repeats).
     */
    public function complete(Reminder $reminder): Response
    {
        $reminder->complete(RecurrenceCalculator::for($reminder->user));

        return response()->noContent();
    }

    /**
     * Snooze the reminder by the preset baked into the signed URL.
     *
     * The preset is a signed query parameter, so it cannot be swapped for
     * another one without invalidating the signature. It still gets checked
     * against the allow-list: a signature proves the value came from us, not
     * that a since-removed preset is still meaningful.
     */
    public function snooze(Request $request, Reminder $reminder): Response
    {
        $preset = (string) $request->query('preset', SnoozePresets::NOTIFICATION_DEFAULT);

        abort_unless(in_array($preset, SnoozePresets::KEYS, true), 422);

        $reminder->snoozeUntil(SnoozePresets::for($reminder->user)->resolve($preset));

        return response()->noContent();
    }
}
