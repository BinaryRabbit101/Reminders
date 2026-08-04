<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReminderStateRequest;
use App\Http\Requests\SnoozeRequest;
use App\Models\Reminder;
use App\Support\RecurrenceCalculator;
use App\Support\ReminderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Acting on a reminder from inside the app: tick it off, push it out, or take
 * the tick back.
 *
 * Every action is row-level and policy-guarded through abilities that
 * delegate to `Reminder::isVisibleTo()`, so either household member can act
 * on a shared reminder and it lands for both of them (shared-reminders spec).
 * The push-notification buttons do the same work without a session — see
 * {@see NotificationActionController}.
 */
class ReminderActionController extends Controller
{
    /**
     * How long the Undo affordance stays on screen, in milliseconds.
     */
    private const UNDO_WINDOW_MS = 5000;

    /**
     * Complete a reminder — or, when it repeats, move it on an occurrence.
     *
     * The prior state rides home on the redirect's flashed toast. That is the
     * whole undo mechanism: the client holds the snapshot for five seconds
     * and posts it back to {@see restore()} if the user changes their mind.
     * Nothing is kept server-side between the two requests, so the window can
     * never go stale, leak between users, or need cleaning up.
     */
    public function complete(Reminder $reminder): RedirectResponse
    {
        Gate::authorize('complete', $reminder);

        // The owner's calculator, not the acting user's: a household member
        // completing a shared daily reminder must not drag its series onto
        // their own clock (RecurrenceCalculator::for()).
        $prior = $reminder->complete(RecurrenceCalculator::for($reminder->user));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Completed'),
            'description' => $reminder->title,
            'duration' => self::UNDO_WINDOW_MS,
            'undo' => [
                'url' => route('reminders.restore', $reminder),
                'data' => $prior,
            ],
        ]);

        return $this->back();
    }

    /**
     * Snooze a reminder to one of the presets, or to a picked local moment.
     */
    public function snooze(SnoozeRequest $request, Reminder $reminder): RedirectResponse
    {
        Gate::authorize('snooze', $reminder);

        $until = $request->snoozedUntil();

        $reminder->snoozeUntil($until);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Snoozed'),
            // Formatted here, like every other date the client renders — on
            // the clock of whoever pressed the button.
            'description' => __('Until :moment', [
                'moment' => ReminderPresenter::for($request->user())->label($until),
            ]),
        ]);

        return $this->back();
    }

    /**
     * Put a reminder back to a state it was in — the Undo action on the
     * completion toast, and the way an already-ticked row is un-ticked.
     *
     * Authorized as an `update`: it is an edit of the same three columns the
     * form could reach anyway, so it needs no ability of its own.
     */
    public function restore(ReminderStateRequest $request, Reminder $reminder): RedirectResponse
    {
        Gate::authorize('update', $reminder);

        $state = $request->state();

        $reminder->restoreState(
            $state['completed_at'],
            $state['due_at'],
            $state['snoozed_until'],
        );

        return $this->back();
    }

    /**
     * Send the user back where they were working — these actions fire from
     * both the Today view and the reminders index.
     */
    private function back(): RedirectResponse
    {
        return back(fallback: route('today'));
    }
}
