<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The estate-standard token check (HUB STANDARDS §2a) for the push API.
 *
 * Same stored key as the widget feed and the Shortcut — `phone_token`, one
 * per account ({@see User::byPhoneToken()}) — read the way every site in the
 * estate reads it, so a caller built for one site works on another by
 * changing only the URL:
 *
 *   `X-Api-Token: <token>` → `Authorization: Bearer <token>` → (GET only)
 *   `?token=<token>`. Nothing else: an old site-specific header such as
 *   `X-Shortcut-Token` is *not* read here and gets the "missing" 401.
 *
 * The value is trimmed first, because a Shortcuts text field can carry a
 * trailing newline.
 *
 * Refusals are a 401 whose JSON carries `ok: false` and a `message` that says
 * whether the token was missing or not valid — the one thing a Shortcut's
 * "Get Dictionary Value → message" step can show on the phone. The older
 * routes (`api/widget/*`, `api/shortcut/*`) keep their own single 403 and
 * their own token reading; this middleware is only on the push routes.
 */
class RequireApiToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokenFrom($request);

        if ($token === null) {
            return $this->refuse('API token missing — send it as the X-Api-Token header (copy it from Settings → Reminders).');
        }

        $user = User::byPhoneToken($token);

        if ($user === null) {
            return $this->refuse('API token not valid — copy it again from Settings → Reminders.');
        }

        $request->setUserResolver(fn (): User => $user);

        return $next($request);
    }

    private function tokenFrom(Request $request): ?string
    {
        $candidates = [
            $request->header('X-Api-Token'),
            $request->bearerToken(),
        ];

        if ($request->isMethod('GET')) {
            $candidates[] = $request->query('token');
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message], 401);
    }
}
