# Push API — Reminders as the estate push service

**Status:** ✅ Shipped 2026-10-10

Other sites and box scripts reach the household's phones through Reminders, because this is
where the phones are subscribed (HUB `SHAPE.md` move 3, owner-approved 2026-10-10). New
sites never add VAPID keys of their own; they call this. It is the HTTP twin of
`php artisan push:send` and sends exactly the same message
(`App\Notifications\AnnouncementPushNotification`).

First callers: Hearth's severe-weather warnings (`hearth:weather-push`) and Homestead's
Sunday season summary (`homestead:season`). Automations still shells into `push:send` on the
box; this route is the alternative for anything that is not on the box or would rather speak
HTTP.

## Authentication

The account's **phone token** — the same stored key as the widget feed and the quick-add
Shortcut (Settings → Reminders), read the estate-standard way (HUB `STANDARDS.md` §2a):

1. `X-Api-Token: <token>`
2. `Authorization: Bearer <token>`
3. `?token=<token>` — **GET only** (the recipients list)

The value is trimmed. Nothing else is read: `X-Shortcut-Token` on these routes is a 401.
Refusals are **401** with `{"ok": false, "message": "API token missing — …"}` or
`"… not valid — …"`. (The older `api/widget/*` and `api/shortcut/*` routes are unchanged and
keep their own reading and their single 403.)

Both routes are throttled at **30 requests a minute**, in front of the token check.

## `POST /api/push`

```json
{
  "title": "Tornado Warning",            // required, ≤120
  "body":  "Oklahoma County. Until 9:45 PM.", // required, ≤500
  "url":   "https://…",                  // optional http(s), ≤2048 — opened on tap; may be off-site
  "tag":   "nws-urn-oid-…",              // optional, ≤100 — replaces an earlier push with the same tag
  "to":    "both"                        // "both" | "me" | [user ids]
}
```

- `"both"` — every member of the token holder's household (a household of one if none).
- `"me"` — the token holder only.
- `[1, 2]` — those members; **any id outside the token holder's household is a 422** and
  nothing is sent. A token can never reach a stranger's phone.

Response `200`:

```json
{ "ok": true, "sent": 3, "failed": 0, "skipped": [], "message": "Sent to 3 devices." }
```

`sent` / `failed` count **devices** (push subscriptions), from the web-push channel's own
reports. An expired subscription (404/410) counts as failed and is pruned, as for every push.
`skipped` lists recipient user ids with no subscribed device — nothing was tried for them.
`422` on validation (`message` + `errors`), `503` if Reminders has no VAPID keys.

```bash
curl -X POST http://192.168.0.164:90/api/push \
  -H "X-Api-Token: $TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"title":"Hello","body":"From curl","to":"me"}'
```

## `GET /api/push/recipients`

Who the token can target, with each member's subscribed-device count:

```json
{ "ok": true, "recipients": [
  { "id": 1, "name": "Keith", "me": true,  "devices": 1 },
  { "id": 2, "name": "Emily", "me": false, "devices": 1 }
] }
```

## Where it lives

- `routes/push-api.php`, registered in `bootstrap/app.php` (prefix `api`, names `api.push.*`)
- `App\Http\Middleware\RequireApiToken` — the §2a token reading, 401 + message
- `App\Http\Controllers\PushApiController` — validation, recipient resolution
- `App\Support\HouseholdPush` — sends and tallies per device via the channel's
  `NotificationSent` / `NotificationFailed` events
- `tests/Feature/PushApiTest.php` — real channel, faked transport (`tests/Support/FakePushTransport.php`)

## Things later work must know

- The tally relies on `AnnouncementPushNotification` being **sent synchronously**. If it is
  ever made `ShouldQueue`, `sent`/`failed` will read 0 — count differently first.
- Callers' tap URLs should be **absolute** (the push opens from Reminders' service worker, so
  a relative `/` would open Reminders, not the caller).
- Rotating a phone token in Settings breaks every caller using it (widget, Shortcut, Hearth's
  `REMINDERS_TOKEN` / `REMINDERS_PUSH_TOKEN`). Update them together.
