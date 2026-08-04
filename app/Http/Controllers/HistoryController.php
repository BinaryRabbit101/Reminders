<?php

namespace App\Http\Controllers;

use App\Support\NotificationHistory;
use App\Support\ReminderPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    /**
     * Everything that was sent, newest first — so a push that was dismissed,
     * missed, or never delivered is still recoverable in the app.
     *
     * Opening the page is what marks it read: `openFor()` captures the unread
     * flags for this render and clears them behind itself, so this visit shows
     * the highlight and the nav badge is already zero by the time the shared
     * prop is resolved.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $presenter = ReminderPresenter::for($user);

        return Inertia::render('history/Index', [
            'history' => NotificationHistory::make()->openFor($user),
            // The feed links each surviving entry to the same edit sheet the
            // Today view opens in place, which needs all three of these.
            'timezone' => $user->timezone(),
            'defaults' => $presenter->formDefaults($user),
            'lists' => $presenter->lists($user),
        ]);
    }
}
