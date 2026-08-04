# Deployment over HTTPS

**Status:** 📝 **Proposed — 2026-08-03**

Push and service workers require a secure context (ARCHITECTURE.md §4). The sibling apps
on the mini-PC are plain HTTP (`192.168.0.164:<port>`) — fine in a browser, fatal for
push. This spec makes the production deployment HTTPS.

## Approach

Recommended: **Tailscale Serve** on the mini-PC. Tailscale already provides the
`minipc.jackal-hippocampus.ts.net` hostname and will mint a real TLS cert; `tailscale
serve` can front the app's local Nginx/PHP-FPM port. The phone must be on the tailnet
(Tailscale app installed) — acceptable for a personal app and true today.

Steps (the implementing agent should use the `minipc` skill for the standard site-deploy
procedure, then layer HTTPS on top):

1. Deploy Reminders like any sibling site: Nginx + PHP-FPM on its own port, SQLite,
   `php artisan schedule:work` running as a service (delivery engine depends on this —
   confirm how LittlePocketMeseum keeps its scheduler alive and copy it).
2. `tailscale serve --bg --https=443 http://127.0.0.1:<port>` (or a path-based mount if
   443 is contested) so the app is `https://minipc.jackal-hippocampus.ts.net`.
3. `APP_URL=https://minipc.jackal-hippocampus.ts.net` and trust the proxy
   (`TrustProxies`) so signed URLs (snooze-and-complete uses them) generate with the
   right scheme/host — a wrong `APP_URL` silently breaks signed-route validation.
4. `php artisan optimize:clear` before `npm run build` (Wayfinder trap, ARCHITECTURE.md §7).
5. Generate production VAPID keys on the box (`php artisan webpush:vapid`; Linux, so no
   OPENSSL_CONF issue) — VAPID keys are per-deployment secrets, don't copy dev keys.

Alternative if Tailscale-on-phone ever becomes unacceptable: real domain + Let's Encrypt
via DNS challenge. Not pursued now; record here if revisited.

## Acceptance criteria

- App loads at `https://minipc.jackal-hippocampus.ts.net` from the phone; installable as
  PWA; `push:test` reaches the installed PWA; scheduler survives a mini-PC reboot.
- A reminder created on the phone pushes to the phone at the due minute — the full loop,
  end to end.

## Open questions

1. ~~Confirm Tailscale is running on the mini-PC and the phone~~ — **Answered
   2026-08-03: it is.** The StoryCampaign Scriptable widget already fetches
   `https://minipc.jackal-hippocampus.ts.net:450` from the phone, so Tailscale Serve
   with per-site ports is proven infrastructure. Pick an unused port for Reminders the
   same way (see the Scriptables project's `/deploy-widget-endpoint` skill for the
   established procedure).
