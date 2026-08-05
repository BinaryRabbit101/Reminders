# Agent instructions — Reminders

## Browser / UI verification

**Always verify browser-driven or visual changes with Laravel Dusk
(`tests/Browser/*`). Never use manual screenshot-taking (Chrome MCP tools, the
`computer` screenshot action, or similar) as a substitute for real test
coverage.** This applies even to purely visual/CSS changes (colour, spacing,
theming) — write or run a Dusk test, don't eyeball it in a browser tab.
Screenshots cost tokens and leave nothing behind for the next change to catch
a regression against; a passing Dusk suite is the actual deliverable.

If a change needs coverage that doesn't exist yet, add a Dusk test rather than
falling back to a manual check.

### Running the suite

1. `rm -f public/hot` if `npm run dev` isn't running (a stale file silently
   breaks Vite asset loading under `php artisan serve`).
2. `cp .env .env.pretest-backup && cp .env.dusk.local .env`
3. `php artisan migrate:fresh --force`
4. Start `php artisan serve --host=127.0.0.1 --port=8000` in the background.
5. `PAO_DISABLE=1 php artisan dusk` — PAO (this box's AI-output condenser)
   misparses Dusk's PHPUnit-style output and reports bogus pass/fail counts;
   always disable it for Dusk. Pest-style `php artisan test` is unaffected.
6. Restore the environment: `cp .env.pretest-backup .env && rm
   .env.pretest-backup`.

Known flakiness: dropdown menus (Reka UI `DropdownMenu`, e.g. the sidebar user
menu) occasionally eat a click that lands right after navigation, before Vue
finishes attaching its listeners. If a dropdown-driven test times out, re-run
it in isolation before treating it as a real regression — but prefer fixing
the test's retry logic over accepting known flakiness.

## Platform quirk

`composer require`/`update` on this box needs `--ignore-platform-req=php`
(the box has PHP 8.4.0; Pest 5 wants ≥8.4.1). `composer config
platform-check false` is already set in `composer.json` so plain `php
artisan` calls aren't affected — don't remove it.
