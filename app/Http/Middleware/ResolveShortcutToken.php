<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a quick-add bearer token into the account behind it.
 *
 * The iPhone Shortcut has no session, no cookie jar and no CSRF token — the
 * token *is* the authentication, exactly as it is for the widget feed. This
 * runs as middleware rather than inside the controller so that everything
 * downstream can go on asking `$request->user()` the way the rest of the app
 * does: the form request scopes its list lookup to the owner, the controller
 * creates through the `reminders()` relation, and neither has to know a token
 * was ever involved.
 *
 * Two things are deliberate:
 *
 * 1. **The header is read first.** The setup recipe puts the token in
 *    `X-Shortcut-Token` precisely so a *write* credential stays out of the
 *    web server's access log, which records query strings. The input fallback
 *    (query or body, whichever `input()` finds) is kept so a URL-only setup
 *    still works — the widget's link has always been shaped that way.
 * 2. **Every failure is the same failure.** Absent, malformed and wrong all
 *    produce one 403 with one message and no `WWW-Authenticate`: the response
 *    must not tell a stranger which accounts exist or how close a guess was
 *    ({@see User::byShortcutToken()}).
 */
class ResolveShortcutToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Shortcut-Token') ?? $request->input('token');

        $user = User::byShortcutToken(is_string($token) ? $token : null);

        abort_if($user === null, 403, 'Invalid shortcut token.');

        $request->setUserResolver(fn (): User => $user);

        return $next($request);
    }
}
