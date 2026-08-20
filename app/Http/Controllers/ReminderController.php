<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReminderRequest;
use App\Models\Reminder;
use App\Support\ListColor;
use App\Support\ReminderPresenter;
use Carbon\CarbonImmutable;
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
     *
     * `?show_completed=1` lifts the default hiding of completed reminders.
     * Hidden by default so the list stays focused on what's still due; the
     * toggle is for the occasional "what did I finish" glance.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $presenter = ReminderPresenter::for($user);

        $listId = $request->integer('list');
        $activeList = $listId > 0 ? $user->lists()->find($listId) : null;
        $showCompleted = $request->boolean('show_completed');

        $reminders = Reminder::query()
            ->visibleTo($user)
            ->with([
                'user',
                'list',
                'alerts',
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
            ->when(! $showCompleted, fn ($query) => $query->pending())
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
            'show_completed' => $showCompleted,
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
        $reminder = $request->user()->reminders()->create($request->reminderAttributes());

        $this->syncAlerts($reminder, $request->alertOffsets());

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
        $previousDueAt = CarbonImmutable::instance($reminder->due_at)->utc();
        $attributes = $request->reminderAttributes();

        $reminder->update($attributes);

        if ($wasShared && ! $attributes['is_shared']) {
            $reminder->filings()->delete();
        }

        // An alert snooze belongs to the occurrence it was set on, and moving
        // `due_at` is what ends that occurrence: left behind, the snooze would
        // pin the alert to a moment in the past forever and it would never
        // fire again (pre-alerts spec). Only a *changed* due moment clears
        // them — re-saving the same time must not throw a snooze away.
        if (! $previousDueAt->equalTo(CarbonImmutable::instance($attributes['due_at'])->utc())) {
            $reminder->clearAlertSnoozes();
        }

        $this->syncAlerts($reminder, $request->alertOffsets());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reminder updated.')]);

        return $this->backToList();
    }

    /**
     * Bring a reminder's pre-alerts in line with the horizons the form posted.
     *
     * Deliberately not `sync()`-by-recreation: an offset that is still ticked
     * keeps its **existing row**, and therefore its `snoozed_until`. Deleting
     * and reinserting would silently un-snooze every alert on every save, and
     * would mint new ids that any already-sent push's action URL no longer
     * matches.
     *
     * @param  list<int>  $offsets
     */
    private function syncAlerts(Reminder $reminder, array $offsets): void
    {
        // whereNotIn against an empty list is "all of them", which is exactly
        // right: nothing ticked means no alerts.
        $reminder->alerts()->whereNotIn('offset_minutes', $offsets)->delete();

        /** @var list<int> $existing */
        $existing = $reminder->alerts()->pluck('offset_minutes')->all();

        foreach (array_diff($offsets, $existing) as $offset) {
            $reminder->alerts()->create(['offset_minutes' => $offset]);
        }

        $reminder->unsetRelation('alerts');
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
