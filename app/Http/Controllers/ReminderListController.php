<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReminderListRequest;
use App\Models\Reminder;
use App\Models\ReminderList;
use App\Models\ReminderListFiling;
use App\Support\ListColor;
use App\Support\ReminderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Managing the lists a user files their reminders under.
 *
 * Its own page rather than a settings section: filing is part of using the
 * app, not part of configuring it, and the page is reached from the reminders
 * index toolbar.
 */
class ReminderListController extends Controller
{
    /**
     * Show the user's lists.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $presenter = ReminderPresenter::for($user);

        return Inertia::render('lists/Index', [
            'lists' => $presenter->lists($user),
            // The palette travels with the page so the swatch picker and the
            // badges are drawn from one definition (App\Support\ListColor).
            'palette' => ListColor::options(),
            // Every list row can open the reminder sheet directly, filed
            // into that list — it needs the same three props the reminders
            // index gives it.
            'defaults' => $presenter->formDefaults($user),
            'timezone' => $user->timezone(),
            // The "add an existing reminder" picker's candidates: everything
            // the user can see — their own reminders plus whatever their
            // household shares with them — soonest first. Completed ones are
            // left out; history is not what this page is for. `filings`
            // is eager-loaded and pre-scoped to the viewer so the presenter
            // can read each row's own filing state with no extra queries.
            'reminders' => Reminder::query()
                ->visibleTo($user)
                ->pending()
                ->with([
                    'user',
                    'list',
                    'alerts',
                    'filings' => fn ($query) => $query->where('user_id', $user->id)->with('list'),
                ])
                ->orderBy('due_at')
                ->get()
                ->map(fn (Reminder $reminder): array => $presenter->present($reminder, $user)),
        ]);
    }

    /**
     * Create a list.
     *
     * Scoped by construction — the list is created *through* the user, so
     * `user_id` is never something the request could influence.
     */
    public function store(ReminderListRequest $request): RedirectResponse
    {
        $request->user()->lists()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('List created.')]);

        return $this->backToLists();
    }

    /**
     * Rename or recolour a list.
     */
    public function update(ReminderListRequest $request, ReminderList $list): RedirectResponse
    {
        Gate::authorize('update', $list);

        $list->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('List updated.')]);

        return $this->backToLists();
    }

    /**
     * Delete a list, leaving its reminders behind.
     *
     * Deleting a *way of filing* must never delete the things filed. The
     * owner's own reminders keep their column nulled explicitly here rather
     * than left to the FK's `ON DELETE SET NULL`, so the rule holds even
     * where SQLite has foreign key enforcement switched off — and so it is
     * the code, not a schema footnote, that says what happens. Co-filers'
     * `ReminderListFiling` rows are the opposite case — a filing *is* the
     * list assignment, so there is nothing meaningful left to null, and they
     * are deleted outright (the FK also cascades this, but the explicit call
     * keeps the same "code says what happens" guarantee).
     */
    public function destroy(ReminderList $list): RedirectResponse
    {
        Gate::authorize('delete', $list);

        $list->reminders()->update(['list_id' => null]);
        $list->filings()->delete();
        $list->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('List deleted. Its reminders were kept.')]);

        return $this->backToLists();
    }

    /**
     * File an existing reminder into this list, from the lists page's
     * picker.
     *
     * The reminder only has to be visible to the caller — their own, or
     * shared with their household — the same rule `ReminderPolicy` uses
     * everywhere else. The list has to be the caller's own, via the same
     * policy `update` uses. Filing is independent per person: the owner's own
     * filing lives on `reminders.list_id` and is what gets overwritten when
     * the caller *is* the owner; anyone else's filing is their own
     * `ReminderListFiling` row, upserted so re-filing moves it rather than
     * stacking a second one, and never touches the owner's column.
     */
    public function assign(Request $request, ReminderList $list, Reminder $reminder): RedirectResponse
    {
        Gate::authorize('update', $list);

        $user = $request->user();
        abort_unless($reminder->isVisibleTo($user), 403);

        if ($reminder->user_id === $user?->id) {
            $reminder->update(['list_id' => $list->id]);
        } else {
            ReminderListFiling::updateOrCreate(
                ['reminder_id' => $reminder->id, 'user_id' => $user?->id],
                ['list_id' => $list->id],
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reminder added to :list.', ['list' => $list->name])]);

        return $this->backToLists();
    }

    /**
     * Clear the caller's own filing of a reminder, from whichever list it is
     * in — the only way a co-filer can reach "no list" at all, since the
     * picker only lets them move between lists they own and the edit sheet's
     * "No list" option is owner-only (`ReminderFormSheet.vue`'s
     * `canChooseList`). Not list-scoped in the route because unfiling does
     * not require knowing which list it was in.
     */
    public function unassign(Request $request, Reminder $reminder): RedirectResponse
    {
        $user = $request->user();
        abort_unless($reminder->isVisibleTo($user), 403);

        if ($reminder->user_id === $user?->id) {
            $reminder->update(['list_id' => null]);
        } else {
            ReminderListFiling::where('reminder_id', $reminder->id)
                ->where('user_id', $user?->id)
                ->delete();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reminder removed from its list.')]);

        return $this->backToLists();
    }

    /**
     * Back where the action was taken — the sheet and the delete dialog both
     * live on the lists page, but a future caller elsewhere should return
     * there instead.
     */
    private function backToLists(): RedirectResponse
    {
        return back(fallback: route('lists.index'));
    }
}
