# PWA Shell

**Status:** ✅ **Implemented — 2026-08-03**

## Close-out

**Deviations:** Service-worker registration shipped here rather than waiting for
push.ts — `resources/js/lib/serviceWorker.ts` (never-throwing) called from `app.ts`;
`favicon.ico` also regenerated (Laravel logo would have shown in ico-preferring
browsers); extra head metas `mobile-web-app-capable` (Chrome deprecation warning
otherwise) and `apple-mobile-web-app-title` added.

**Things later work must know:**
- **push.ts must NOT register the SW again** — use `await navigator.serviceWorker.ready`.
- `sw.js` is network-only by design (PasswordVault stance): activate purges all caches,
  fetch is a bare passthrough. Offline support is deliberately absent.
- The `<meta name="vapid-public-key">` tag is wrapped in
  `@if (config('webpush.vapid.public_key'))` — it appears automatically once the webpush
  package + VAPID env keys land (push-notifications spec). No blade error before then.
- Icons are white `#fafafa` bell on `#0a0a0a`, drawn with PHP GD (no rasterizer on this
  machine — magick/inkscape/sharp all absent; generator script was scratchpad-only, not
  in the repo). Maskable-safe (glyph within 80% circle); `badge-72.png` is
  transparent-background white glyph because Android uses its alpha as a mask.
- `notificationclick` default deep-link is `/today`; push payloads can override via a
  `url` in their data.

Make the app installable to a phone home screen as a standalone PWA. This is a direct copy
of the proven StoryCampaign pattern — no Workbox, no `vite-plugin-pwa`.

## Files to create (copy from `C:\Users\binar\Documents\websites\StoryCampaign`, then adapt)

| Source (StoryCampaign) | Target here | Adaptation |
|---|---|---|
| `public\manifest.webmanifest` | same | `name`/`short_name` → "Reminders", `start_url` → `/today`, keep `display: standalone`, colors `#0a0a0a`, icons 192+512 `"purpose": "any maskable"` |
| `public\sw.js` | same | Keep as-is: `skipWaiting`/`clients.claim`, `push` handler with JSON-parse fallback, `notificationclick` that focuses an existing window and `client.navigate(url)` |
| `public\icons\` (icon-192.png, icon-512.png, badge-72.png) | same | Generate simple Reminders icons (a bell glyph on `#0a0a0a` works; SVG → PNG) |
| `apple-touch-icon.png`, `favicon.svg` | same | ditto |
| `resources\views\app.blade.php` head block | merge into ours | `<link rel="manifest">`, `theme-color` meta, `apple-mobile-web-app-capable`, and the `vapid-public-key` meta (harmless before push ships) |

Also compare with PasswordVault's `public\serviceworker.js`: it deliberately caches
nothing (network-only fetch + cache purge on activate). **Adopt that network-only stance**
in our `sw.js` — Reminders data must never be stale, and offline support is out of scope.

## Notes

- Keep the existing dark-mode pre-paint script in `app.blade.php` untouched (inline
  `$appearance` script + `oklch` background — NorthernCall learned a transparent
  `--background` breaks surfaces).
- Service worker registration happens in `lib/push.ts` (push-notifications spec); this
  spec only needs the files served. Registering the SW eagerly on app boot is fine too —
  decide with the push spec's agent, whichever ships first.
- `localhost` is a secure context — installability testable in local Chrome devtools
  (Application → Manifest).

## Acceptance criteria

- Chrome devtools Application tab shows a valid manifest, no installability warnings,
  service worker registered and activated.
- Lighthouse PWA installability check passes locally.
- App opens standalone (no browser chrome) when installed, landing on `/today`.

## Open questions

1. Icon design — generated glyph acceptable, or is there a preferred aesthetic/color?
