# Quick-Add Shortcut

**Status:** ✅ **Implemented — 2026-09-02** (the endpoint is live in the repo; the
Shortcut on the phone needs the code deployed to the mini-PC first)

## Close-out

**Deviations:** none of substance. Two additions the build turned up:

- `due_time` accepts `5:00 PM` as well as `17:00`, normalised in
  `ShortcutReminderRequest::normaliseTime()`. The obvious *Format Date* preset on a phone
  set to 12-hour time yields the former, and refusing it would have been correct and
  useless.
- A quick-add with **no date** does not simply mean "today". If the hour it would land at
  has already gone by, it rolls to tomorrow — otherwise adding something at nine in the
  evening creates a reminder due that morning, overdue on arrival and pushed on the next
  scheduler tick. A time posted without a date decides the day itself; an explicit past
  date is left alone (somebody who typed it meant it).

**Things later work must know:**

- **Two bearer columns now, not one.** `widget_token` reads, `shortcut_token` writes, and
  neither resolves on the other's route — there are tests in both directions
  (`ShortcutReminderTest::test_a_widget_token_cannot_create_a_reminder`). The
  constant-time scan moved into `User::byToken()`; `byWidgetToken()`/`byShortcutToken()`
  are now thin wrappers over it. The column name reaches a query builder, so it is checked
  against `User::TOKEN_COLUMNS` — keep that guard if you add a third.
- **Local wall-time → UTC lives in `App\Support\DueMoment`.** `ReminderRequest` used to do
  it inline and now calls through it, so the "single place" claim in that class's docblock
  is still true with two writers. Anything else that accepts a date and a time from a
  client belongs here too.
- Auth is `App\Http\Middleware\ResolveShortcutToken`, applied in `bootstrap/app.php`'s
  `then:` closure, which sets the user resolver — so the form request and controller look
  like ordinary session-backed code. Route file `routes/shortcut.php`, prefix
  `api/shortcut`, name `shortcut.`, `throttle:20,1`.
- The reply's `message` key is load-bearing: a 201, a 422 and a 403 all carry one, so the
  Shortcut reads the same dictionary key whatever happened.
- Settings hands out endpoint and key as **two** fields, unlike the widget's single
  assembled link, because the recipe sends the key as a header — keep it out of URLs.
- **Deployment must**: the endpoint is only reachable from a phone once this is deployed
  to the mini-PC (`https://minipc.jackal-hippocampus.ts.net:452`), and `APP_URL` decides
  what the settings page prints as the endpoint. The key is generated per account on
  Settings → Reminders after deploying.
- Suite at close: 500 Pest tests / 2038 assertions, 38 Dusk tests.

## The Shortcut recipe

Build it in the Shortcuts app on the phone; six actions. Settings → Reminders →
**Quick add shortcut** has the endpoint and the key to paste in.

1. **Ask for Input** — Input type *Text*, prompt "What's the reminder?"
2. **Ask for Input** — Input type *Date*, prompt "What day?"
3. **Format Date** — take the date from step 2, *Custom* format `yyyy-MM-dd`.
4. **Ask for Input** — Input type *Time*, prompt "What time?" (then **Format Date**,
   *Custom* format `HH:mm` — or leave it 12-hour, the endpoint accepts both).
5. **Get Contents of URL** —
   - URL: `https://minipc.jackal-hippocampus.ts.net:452/api/shortcut/reminders`
   - Method: `POST`
   - Headers: `X-Shortcut-Token` → the key from settings
   - Request Body: `JSON`, three text fields: `title` (step 1), `due_date` (step 3),
     `due_time` (step 4). Optional extras: `notes`, `list` (a list name), `is_shared`.
6. **Get Dictionary Value** — `message` from *Contents of URL* — then **Show
   Notification**.

Step 6 is why every response carries `message`: a refused key, a bad date and a created
reminder all land in the same notification, so the shortcut never fails silently.

Then: *Add to Siri* ("add reminder"), and pin it to the Action Button, the Lock Screen or
the home screen. Skipping the date and time prompts (deleting actions 2–4 and their JSON
fields) gives a one-tap version that lands on the account's default reminder hour.

Tailscale has to be connected on the phone, same as for the widget.

---

An iOS Shortcut that creates a reminder without opening the app: you run it (Siri, the
Action Button, the Lock Screen, the share sheet), it asks for the text, a date and a
time, posts them, and shows a notification saying what it made.

The widget reads. This writes. That single difference drives every decision below.

## The write endpoint

`POST /api/shortcut/reminders`, registered in `bootstrap/app.php`'s `then:` closure
alongside `routes/widget.php` — outside the web middleware group, for the reason the
widget feed is: the caller is the Shortcuts app on a phone, with no session, no cookie
jar and no CSRF token. It carries the `api/` prefix for the same reason too — exceptions
on `api/*` render as JSON, so a refusal is something the Shortcut can read.

Throttled harder than the feed (`throttle:20,1`): this one creates rows, and a human
tapping a shortcut will never come near twenty a minute.

### Authentication — a *second* token

A new `shortcut_token` column on `users`, minted and rolled exactly like `widget_token`,
resolved with the same non-short-circuiting `hash_equals` scan.

It is deliberately **not** the widget token reused. That token already sits in a
Scriptable `CONFIG` on a phone and in the settings page's copy field, and it buys its
holder read access to a reminder list. Letting the same string also *write* would
silently upgrade a credential that is already out in the world, and would make "roll the
widget link" and "roll the write key" one button when they are two different risks. Two
columns, two links, two revocations.

Resolution happens in middleware (`ResolveShortcutToken`) rather than in the controller,
so `$request->user()` is set the way the rest of the app expects it and the form request
can scope its rules to the owner. The token is read from the `X-Shortcut-Token` header
first, then from the request input (query or body) — the header is what the setup recipe
uses, so the write key stays out of the server's access log; the input fallback is there
so a URL-only setup still works.

Every failure is one 403 with one message, as with the feed: absent, malformed and wrong
must be indistinguishable.

### Request

| Field | Rules | Default |
|---|---|---|
| `title` | required, ≤255 | — |
| `notes` | nullable, ≤2000 | none |
| `due_date` | nullable, `Y-m-d` | today, rolled to tomorrow if the default time has already passed |
| `due_time` | nullable, `H:i` or `H:i:s` | the account's default reminder time |
| `list` | nullable, ≤255 — a list **name**, matched case-insensitively against the poster's own lists | no list |
| `is_shared` | nullable boolean, ignored without a household | false |

`list` is a name rather than an id because nobody is going to hand-maintain a numeric id
inside a Shortcut. An unknown name is a validation error, not a silent drop — a shortcut
that quietly stops filing things is worse than one that says so.

The date/time pair is read on the account's own timezone and converted once, through the
same seam the web form uses. `ReminderRequest`'s conversion moves to
`App\Support\DueMoment` so that stays literally true rather than becoming two copies.

No recurrence, no pre-alerts, no silencing. A quick-add is a quick-add; the sheet in the
app is where a reminder gets shaped.

### Response

`201` with the created row, plus a pre-assembled `message` the Shortcut shows verbatim —
the client assembles no strings here either (today-view close-out):

```json
{
  "id": 42,
  "title": "Take out bins",
  "due_at": "2026-09-03T22:00:00+00:00",
  "due_label": "Wed, Sep 3, 5:00 PM",
  "list": "Home",
  "is_shared": false,
  "message": "Reminder set — Wed, Sep 3, 5:00 PM"
}
```

`422` carries Laravel's usual `{message, errors}`; the Shortcut shows `message`.

## Settings

A "Quick add shortcut" panel on `settings/reminders`, below the widget one, with its own
`POST settings/reminders/shortcut-token` regenerate action. It shows the endpoint URL and
the token as **separate** copy fields, unlike the widget's single ready-to-paste link:
the recipe puts the token in a header, and a URL with the key baked into the query string
is one paste away from a log file.

## The Shortcut itself

Hand-built in the Shortcuts app (six actions) rather than shipped as a `.shortcut` file —
unsigned shortcut files need the "untrusted shortcuts" toggle and break on import often
enough that a recipe is the more reliable deliverable. The recipe lives in this spec's
shipped copy, so the next person setting up a phone has it.

## Acceptance criteria

- Pest: token auth (valid / absent / malformed / wrong / *widget* token rejected), header
  and input token sources, creation with explicit date+time, each default path, list by
  name (match, case-insensitive match, unknown → 422, another account's list → 422),
  sharing gated on household, timezone conversion on a non-app-default account, response
  shape, throttling.
- Dusk: the settings panel — absent until generated, endpoint and token shown after,
  rolling replaces the token.
- Green `composer test`.
