<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReminderListRequest;
use App\Models\ReminderList;
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
        return Inertia::render('lists/Index', [
            'lists' => ReminderPresenter::for($request->user())->lists($request->user()),
            // The palette travels with the page so the swatch picker and the
            // badges are drawn from one definition (App\Support\ListColor).
            'palette' => ListColor::options(),
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
     * column is nulled explicitly here rather than left to the FK's
     * `ON DELETE SET NULL`, so the rule holds even where SQLite has foreign
     * key enforcement switched off — and so it is the code, not a schema
     * footnote, that says what happens.
     */
    public function destroy(ReminderList $list): RedirectResponse
    {
        Gate::authorize('delete', $list);

        $list->reminders()->update(['list_id' => null]);
        $list->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('List deleted. Its reminders were kept.')]);

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
