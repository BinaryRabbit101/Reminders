<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireApiToken;
use App\Http\Middleware\ResolveShortcutToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The push-notification action buttons, registered outside the web
        // group on purpose: a service worker fetch has neither a CSRF token
        // nor a dependable session, so `signed` is the entire authorization
        // and the session/cookie stack would only get in the way. Model
        // binding has to be asked for explicitly out here.
        then: function (): void {
            Route::middleware(['signed', SubstituteBindings::class])
                ->prefix('notification-actions')
                ->name('notification-actions.')
                ->group(base_path('routes/notification-actions.php'));

            // The home-screen widget's feed, out here for the same reason:
            // Scriptable on a phone has no session and no CSRF token, so the
            // per-user `?token=` is the whole authorization. Throttled
            // because a bearer token on an unauthenticated route is exactly
            // the thing somebody would sit and guess at; sixty a minute is
            // far more than a widget refreshing every fifteen will ever use.
            Route::middleware(['throttle:60,1'])
                ->prefix('api/widget')
                ->name('widget.')
                ->group(base_path('routes/widget.php'));

            // The iPhone Shortcut's quick-add endpoint — out here for the
            // third time for the same reason, and with its own token
            // (ResolveShortcutToken) rather than the widget's, because this
            // one writes. Throttled harder than the feed: a widget polls, but
            // nobody taps a shortcut twenty times a minute, so anything past
            // that is either a stuck loop or somebody guessing.
            Route::middleware(['throttle:20,1', ResolveShortcutToken::class])
                ->prefix('api/shortcut')
                ->name('shortcut.')
                ->group(base_path('routes/shortcut.php'));

            // The estate push service (HUB SHAPE.md move 3): other sites
            // and box scripts push to the household's phones over HTTP.
            // Same stored key, but read the estate-standard way
            // (RequireApiToken: X-Api-Token / Bearer, 401 + message). The
            // throttle sits in front of the token check so guessing is
            // limited too; thirty a minute is far past what a weather
            // warning or a watcher ever sends.
            Route::middleware(['throttle:30,1', RequireApiToken::class])
                ->prefix('api')
                ->name('api.')
                ->group(base_path('routes/push-api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
