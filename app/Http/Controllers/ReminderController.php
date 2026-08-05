<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReminderRequest;
use App\Models\Reminder;
use App\Support\ListColor;
use App\Support\ReminderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReminderController extends Controller
{
    /**
     * List every reminder the authenticated user can see, soonest first —
     * their own, plus whatever their household shares with them.
     *
     * `?list=` narrows the page to a single list. The id is resolved against
     * the user's *own* lists, so an id belonging to somebody else resolves to
     * nothing and the filter is simply dropped rather than 403-ing or leaking
     * the fact that the list exists. Filtering on `list_id` also cannot show
     * another account's rows: only an owner ever files their own reminders.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $presenter = ReminderPresenter::for($user);

        $listId = $request->integer('list');
        $activeList = $listId > 0 ? $user->lists()->find($listId) : null;

        $reminders = Reminder::query()
            ->visibleTo($user)
            ->with([
                'user',
                'list',
                'filings' => fn ($query) => $query->where('user_id', $user->id)->with('list'),
            ])
            // Matches either half of the viewer's own filing: their own
            // reminder filed under the list directly, or a shared one they
            // co-filed via ReminderListFiling. Both conditions are nested in
            // their own group before the `orWhereHas` — appending it at the
            // top level would OR against visibleTo()'s own grouping instead
            // of narrowing within it, which would leak reminders the viewer
            // otherwise couldn't see.
            ->when($activeList !== null, fn ($query) => $query->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('user_id', $user->id)->where('list_id', $activeList->id))
                ->orWhereHas('filings', fn ($query) => $query->where('user_id', $user->id)->where('list_id', $activeList->id))
            ))
            ->orderBy('due_at')
            ->get()
            ->map(fn (Reminder $reminder): array => $presenter->present($reminder, $user));

        return Inertia::render('reminders/Index', [
            'reminders' => $reminders,
            'timezone' => $user->timezone(),
            'defaults' => $presenter->formDefaults($user),
            'lists' => $presenter->lists($user),
            // Which chip is lit. Null both for "All" and for an id that did
            // not resolve, so a stale link lands on the unfiltered page.
            'active_list_id' => $activeList?->id,
            // The fixed palette, so the reminder sheet's inline "new list"
            // dialog can draw the same swatch picker /lists uses, without a
            // round trip to fetch it.
            'palette' => ListColor::options(),
        ]);
    }

    /**
     * Store a newly created reminder.
     */
    public function store(ReminderRequest $request): RedirectResponse
    {
        $request->user()->reminders()->create($request->reminderAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reminder created.')]);

        return $this->backToList();
    }

    /**
     * Update the given reminder.
     */
    public function update(ReminderRequest $request, Reminder $reminder): RedirectResponse
    {
        Gate::authorize('update', $reminder);

        // A household member's filing only makes sense while they can still
        // see the reminder — un-sharing takes that away, so a shared-to-
        // private transition clears whatever co-filings existed rather than
        // leaving a stranded row that keeps inflating that list's count. The
        // new value is read off the request attributes, not the model after
        // `update()`, so the comparison is against what's actually about to
        // be written rather than in-memory state.
        $wasShared = $reminder->is_shared;
        $attributes = $request->reminderAttributes();

        $reminder->update($attributes);

        if ($wasShared && ! $attributes['is_shared']) {
            $reminder->filings()->delete();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reminder updated.')]);

        return $this->backToList();
    }

    /**
     * Delete the given reminder.
     */
    public function destroy(Reminder $reminder): RedirectResponse
    {
        Gate::authorize('delete', $reminder);

        $reminder->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reminder deleted.')]);

        return $this->backToList();
    }

    /**
     * Send the user back where they were working — the form sheet is opened
     * from both the reminders index and the Today view — falling back to the
     * index when there is nowhere to go back to.
     */
    private function backToList(): RedirectResponse
    {
        return back(fallback: route('reminders.index'));
    }
}
