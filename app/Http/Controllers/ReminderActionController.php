<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteReminderRequest;
use App\Http\Requests\ReminderStateRequest;
use App\Http\Requests\SnoozeRequest;
use App\Models\Reminder;
use App\Notifications\ReminderDueNotification;
use App\Support\RecurrenceCalculator;
use App\Support\ReminderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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
     *
     * An optional `note` rides along from the "How did it go?" dialog and the
     * note page ({@see note()}); it is filed on the completion, and Undo
     * leaves it there like the rest of the completion log.
     */
    public function complete(CompleteReminderRequest $request, Reminder $reminder): RedirectResponse
    {
        Gate::authorize('complete', $reminder);

        // The owner's calculator, not the acting user's: a household member
        // completing a shared daily reminder must not drag its series onto
        // their own clock (RecurrenceCalculator::for()).
        $prior = $reminder->complete(RecurrenceCalculator::for($reminder->user), $request->note());

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

        // Back to the page the tick came from — except the note page, which
        // is a single-purpose stop on the way somewhere else: going "back"
        // to it would show a form for the very reminder just completed. It
        // lands on Today instead, toast and Undo intact.
        if ($this->cameFromNotePage($reminder)) {
            return to_route('today');
        }

        return $this->back();
    }

    /**
     * The "How did it go?" page — where a push notification's Complete button
     * sends a reminder with `ask_for_note`, since a lock-screen button cannot
     * take text ({@see ReminderDueNotification}).
     *
     * Unlike the push's other buttons this is an ordinary session route, not
     * a signed one: writing a note means opening the app anyway, so the user
     * is signed in (or signs in and is sent back here), and the page is
     * guarded by the same `complete` ability as the tick itself — visible to
     * whoever may complete the reminder, forbidden to everyone else.
     *
     * The page itself only renders; saving posts to {@see complete()} like
     * every other tick. What it has to work out is whether there is still
     * anything to complete, because a push can be tapped long after the fact:
     *
     * - a one-off that already has a `completed_at` is plainly done;
     * - a repeating reminder never has one — it steps on to its next
     *   occurrence instead — so the push stamps the `due_at` it was sent for
     *   as `?due=<unix>`, and a series that has moved past it has had that
     *   occurrence dealt with. Raw `due_at` rather than the effective moment
     *   on purpose: a snooze moves the occurrence without handling it, and
     *   a snoozed reminder is still very much waiting for its note.
     *
     * Either way the page says so and offers the way to Today rather than a
     * form that would complete the *next* occurrence by mistake. Visited with
     * no `?due=` (typed in, bookmarked) it trusts the reminder as it stands.
     */
    public function note(Request $request, Reminder $reminder): Response
    {
        Gate::authorize('complete', $reminder);

        $user = $request->user();

        $isDone = $reminder->completed_at !== null
            || ($request->filled('due') && $reminder->due_at->getTimestamp() !== $request->integer('due'));

        return Inertia::render('reminders/Note', [
            'reminder' => ReminderPresenter::for($user)->present($reminder, $user),
            'is_done' => $isDone,
            'note_max' => CompleteReminderRequest::NOTE_MAX,
        ]);
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
     * Silence a reminder's pushes, or let it speak again.
     *
     * Lives here, next to snooze, because it is the same kind of thing: an
     * act on a row rather than an edit of one. The toggle is also reachable
     * from the edit sheet's checkbox — this is the one-tap version, on the
     * menu the user already opens to say "not now".
     *
     * Authorized as an `update` for the reason {@see restore()} is: it writes
     * a column the form could reach anyway, so it needs no ability of its
     * own. Inheriting the household rule is right rather than incidental —
     * silence belongs to the reminder, not to whoever is looking at it, so
     * either member switching it off switches it off for both.
     */
    public function silence(Reminder $reminder): RedirectResponse
    {
        Gate::authorize('update', $reminder);

        $silenced = $reminder->toggleSilence();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $silenced ? __('Silenced') : __('Unsilenced'),
            'description' => $silenced
                ? __('No push notifications for :title.', ['title' => $reminder->title])
                : __('Push notifications are back on for :title.', ['title' => $reminder->title]),
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
     * Whether the request came from this reminder's note page.
     *
     * Read off the previous URL (the session's record of the last page
     * visited, or the Referer) and compared by path alone, so the `?due=`
     * query and whichever host the page was reached on do not matter.
     */
    private function cameFromNotePage(Reminder $reminder): bool
    {
        $previous = parse_url(url()->previous(), PHP_URL_PATH);
        $note = parse_url(route('reminders.note', $reminder), PHP_URL_PATH);

        return is_string($previous) && $previous === $note;
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
