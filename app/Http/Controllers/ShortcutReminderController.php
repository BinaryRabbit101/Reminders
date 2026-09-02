<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShortcutReminderRequest;
use App\Support\ReminderPresenter;
use Illuminate\Http\JsonResponse;

/**
 * The quick-add endpoint behind the iPhone Shortcut.
 *
 * The write counterpart to {@see WidgetFeedController}: the widget reads what
 * is due, this creates it. Both sit outside the web middleware group because
 * the caller is a phone with no session and no CSRF token, but they do not
 * share a token — see the `shortcut_token` migration for why a read link must
 * not quietly become a write key.
 *
 * There is no `index`, `update` or `destroy` here and there should not be. A
 * Shortcut is a one-way button you press while walking; anything that needs
 * you to look at a list first belongs in the app.
 */
class ShortcutReminderController extends Controller
{
    /**
     * Create a reminder for whoever holds the token.
     *
     * The reply carries a ready-made `message` alongside the raw fields, and
     * the Shortcut shows *that* string rather than assembling one from the
     * parts — the same division of labour every other client in this app
     * follows (today-view close-out). It matters more here than anywhere
     * else: string handling in the Shortcuts editor is a dozen drag-and-drop
     * actions, and the phone has no idea what timezone the account keeps.
     *
     * `201` rather than a redirect, because nothing is going to follow it.
     */
    public function store(ShortcutReminderRequest $request): JsonResponse
    {
        $user = $request->user();

        $reminder = $user->reminders()->create($request->reminderAttributes());

        $label = ReminderPresenter::for($user)->label($reminder->due_at);

        return response()->json([
            'id' => $reminder->id,
            'title' => $reminder->title,
            'due_at' => $reminder->due_at->toIso8601String(),
            'due_label' => $label,
            'list' => $request->listName(),
            'is_shared' => $reminder->is_shared,
            'message' => 'Reminder set — '.$label,
        ], 201);
    }
}
