<?php

namespace App\Http\Controllers;

use App\Support\ListColor;
use App\Support\ReminderPresenter;
use App\Support\TodayBoard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TodayController extends Controller
{
    /**
     * The landing page: what needs attention now.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $presenter = ReminderPresenter::for($user);

        return Inertia::render('Today', [
            'board' => TodayBoard::make()->for($user),
            'timezone' => $user->timezone(),
            'defaults' => $presenter->formDefaults($user),
            // The form sheet opens from here too, so it needs the same lists
            // the reminders index gives it.
            'lists' => $presenter->lists($user),
            'palette' => ListColor::options(),
        ]);
    }
}
