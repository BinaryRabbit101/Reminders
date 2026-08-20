<?php

namespace App\Http\Controllers;

use App\Http\Requests\SnoozeRequest;
use App\Models\Reminder;
use App\Models\ReminderAlert;
use App\Support\ReminderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Acting on a reminder's *pre-alert* from inside the app.
 *
 * Only one action so far, and only one column it may write:
 * `reminder_alerts.snoozed_until`. Pushing the hour-before nudge out by ten
 * minutes must never move the reminder it is a nudge about, so
 * `reminders.snoozed_until` is not reachable from here at all — that is
 * {@see ReminderActionController::snooze()}'s job and a different decision.
 *
 * Authorization is the reminder's existing `snooze` ability: an alert has no
 * visibility of its own, it belongs to whoever the reminder belongs to, which
 * means either household member may act on a shared reminder's alerts.
 */
class ReminderAlertController extends Controller
{
    /**
     * Snooze one pre-alert to a preset, or to a picked local moment.
     *
     * The route scopes `{alert}` to `{reminder}`, so an alert id belonging to
     * a different reminder is a 404 before this method is reached and the
     * policy check above can trust the pair it is handed.
     *
     * A snooze past the main due moment is accepted and then simply never
     * fires — the delivery engine only fires an alert strictly before its
     * reminder's effective due moment. That is correct: the main notification
     * is coming anyway, and refusing the input would be a rule the user has
     * no way to see.
     */
    public function snooze(SnoozeRequest $request, Reminder $reminder, ReminderAlert $alert): RedirectResponse
    {
        Gate::authorize('snooze', $reminder);

        $until = $request->snoozedUntil();

        $alert->snoozeUntil($until);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Alert snoozed'),
            'description' => __('Until :moment', [
                'moment' => ReminderPresenter::for($request->user())->label($until),
            ]),
        ]);

        return back(fallback: route('today'));
    }
}
