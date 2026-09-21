<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ReminderEventsFeed;
use App\Support\WidgetFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The read-only JSON feeds behind the iPhone home-screen widget and, since
 * D1, a downstream aggregator (Homestead) polling for completed chores.
 *
 * Registered in bootstrap/app.php *outside* the web middleware group, for the
 * same reason the notification actions are: the caller is Scriptable on a
 * phone (or, for `events`, a server-side poller), which has no session, no
 * cookie jar and no CSRF token. The query token is the entire authentication
 * — see {@see User::byPhoneToken()} for why the comparison is what it is.
 *
 * The token is per-account rather than app-wide (two accounts share this app)
 * and every feed here answers with *that account's* visible data, household
 * sharing included.
 */
class WidgetFeedController extends Controller
{
    /**
     * Everything the widget draws, for whoever holds the token.
     *
     * `?scope=shared` narrows the whole payload — rows and counts alike — to
     * household-shared reminders. It is for a shared display (Hearth), which
     * has no business putting one person's private errands on the wall; any
     * other value, or none, is the full feed the phone widget has always had.
     *
     * Every failure is the same failure. A missing token, a malformed one and
     * a wrong one all produce one 403 with one message: the response must not
     * be an oracle that tells a stranger which accounts exist or how close a
     * guess was. There is deliberately no `WWW-Authenticate`, no hint, and no
     * difference in shape between the three.
     */
    public function today(Request $request): JsonResponse
    {
        return response()->json(WidgetFeed::make()->for(
            $this->tokenHolder($request),
            sharedOnly: $request->query('scope') === 'shared',
        ));
    }

    /**
     * Completed reminders since `since` (or the last 7 days, if omitted),
     * for a downstream aggregator to diff against what it has already seen.
     *
     * Auth and visibility are deliberately identical to {@see today()} — see
     * that method's docblock, which applies here unchanged. This endpoint is
     * not a better oracle than that one: the same single 403 covers a
     * missing, malformed or wrong token.
     *
     * Read-only: a `SELECT` and a projection over the append-only
     * `reminder_completions` table, nothing written.
     */
    public function events(Request $request): JsonResponse
    {
        $user = $this->tokenHolder($request);

        $since = $request->query('since');

        return response()
            ->json(ReminderEventsFeed::make()->for($user, is_string($since) ? $since : null))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * The account a `?token=` query parameter resolves to, or the one 403
     * every failure shares — see {@see today()}'s docblock for why a missing,
     * malformed and wrong token must never be told apart.
     */
    private function tokenHolder(Request $request): User
    {
        $token = $request->query('token');

        $user = User::byPhoneToken(is_string($token) ? $token : null);

        abort_if($user === null, 403, 'Invalid token — copy it again from Settings → Reminders.');

        return $user;
    }
}
