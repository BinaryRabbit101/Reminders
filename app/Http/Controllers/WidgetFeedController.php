<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\WidgetFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The read-only JSON feed behind the iPhone home-screen widget.
 *
 * Registered in bootstrap/app.php *outside* the web middleware group, for the
 * same reason the notification actions are: the caller is Scriptable on a
 * phone, which has no session, no cookie jar and no CSRF token. The query
 * token is the entire authentication — see {@see User::byWidgetToken()} for
 * why the comparison is what it is.
 *
 * The token is per-account rather than app-wide (two accounts share this app)
 * and the feed answers with *that account's* visible reminders, household
 * sharing included.
 */
class WidgetFeedController extends Controller
{
    /**
     * Everything the widget draws, for whoever holds the token.
     *
     * Every failure is the same failure. A missing token, a malformed one and
     * a wrong one all produce one 403 with one message: the response must not
     * be an oracle that tells a stranger which accounts exist or how close a
     * guess was. There is deliberately no `WWW-Authenticate`, no hint, and no
     * difference in shape between the three.
     */
    public function today(Request $request): JsonResponse
    {
        $token = $request->query('token');

        $user = User::byWidgetToken(is_string($token) ? $token : null);

        abort_if($user === null, 403, 'Invalid widget token.');

        return response()->json(WidgetFeed::make()->for($user));
    }
}
