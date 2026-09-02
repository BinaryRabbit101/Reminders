# Quick-Add Shortcut

**Status:** ✅ **Implemented — 2026-09-02** (the endpoint is live in the repo; the
Shortcut on the phone needs the code deployed to the mini-PC first)

## Close-out

**Amended 2026-09-02, hours after shipping: one token, not two.** This first shipped with
a `shortcut_token` separate from the widget's, so that a read credential could not become
a write one. That was wrong in practice and the owner said so immediately: both keys live
on the same phone, in the same person's hands, behind the same tailnet, so the split
isolated nothing — it just meant two things to paste, and a widget link that was silently
refused when pasted into the Shortcut, which is the first thing anybody would try. (The
original argument has been deleted rather than kept as an alternative; the spec body below
now describes what the code actually does.)

`widget_token` was therefore **renamed** to `phone_token` (rename, not re-mint, so every
Scriptable CONFIG already in the field kept working), `shortcut_token` was dropped, and
`User::byPhoneToken()` is now the single resolver behind both surfaces. The cost, stated
on the settings page and tested: rolling the key revokes the widget and the Shortcut at
once.

**Fixed 2026-09-02, same day: a chosen 8:30 was stored as 9:00.** The reminder kept its
date and lost its time, and the account's default hour filled the gap — with a "Reminder
set — 9:00 AM" confirmation that read like success. Three things had to be true at once,
and all three are now closed:

- **`ConvertEmptyStringsToNull` runs before validation**, so an empty `due_time` and an
  absent one were the same null by the time any rule saw them. The rules now branch on
  `$this->has('due_time')` — the *key's presence* — because absent means "use my default
  hour" (the one-tap shortcut) while sent-and-empty means a Shortcut variable resolved to
  nothing. Empty is now a 422 naming the field to go and fix.
- **The recipe asked for the day and the time in two separate prompts.** iOS's date
  picker already carries a time, so the two Format Date actions now read the *same*
  variable — one picker cannot disagree with itself or half-fail.
- **iOS 17+ writes U+202F, not a space, before AM/PM.** `normaliseTime()` folds Unicode
  spaces to ASCII first; without it, `8:30 AM` straight out of a 12-hour Format Date
  would have been refused over a character nobody can see.

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

- **One bearer column: `phone_token`.** The widget feed and the quick-add endpoint both
  resolve it through `User::byPhoneToken()`, and there are tests in both directions
  (`ShortcutReminderTest::test_the_key_from_the_widget_link_creates_reminders_too` and
  `test_the_same_key_reads_the_widget_feed`) because a second credential is exactly the
  sort of thing that grows back. The constant-time `hash_equals` scan is unchanged and
  still must not be "optimized" into a `where()` clause.
- **The refusal message is shared too**, and deliberately not surface-specific: it used
  to say "Invalid shortcut token.", which is precisely what made the owner think a second
  key existed. Both now say `Invalid token — copy it again from Settings → Reminders.`
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
- Settings has **one** panel — "Your phone's key" — handing the one secret out in the two
  shapes its surfaces want: the whole feed URL for the widget's CONFIG, and the endpoint
  plus the bare key for the Shortcut, which sends it as a header rather than in a URL.
- **Deployment must**: the endpoint is only reachable from a phone once this is deployed
  to the mini-PC (`https://minipc.jackal-hippocampus.ts.net:452`), and `APP_URL` decides
  what the settings page prints as the endpoint. The key is generated per account on
  Settings → Reminders after deploying. An account that already had a widget link needs
  nothing new — that key is the key.
- Suite at close: 496 Pest tests / 2018 assertions, 38 Dusk tests.

## The Shortcut recipe

Build it in the Shortcuts app on the phone; seven actions. Settings → Reminders →
**Your phone's key** has the endpoint and the key to paste in — and if the widget is
already set up, that same key is the one already in its CONFIG.

1. **Ask for Input** — *Text*, prompt "What's the reminder?"
2. **Ask for Input** — *Date*, prompt "When?" — iOS's date picker carries a **time as
   well as a day**, so this one prompt is the whole due moment.
3. **Format Date** — *Provided Input* from step 2, *Custom* format `yyyy-MM-dd`. Rename
   the variable (tap the pill → *Rename*) to **Due Date**.
4. **Format Date** — *Provided Input* from step 2 **again**, *Custom* format `HH:mm`.
   Rename to **Due Time**.
5. **Get Contents of URL** —
   - URL: `https://minipc.jackal-hippocampus.ts.net:452/api/shortcut/reminders`
   - Method: `POST`
   - Headers: `X-Shortcut-Token` → the key from settings
   - Request Body: `JSON`, three text fields: `title` (step 1), `due_date` (Due Date),
     `due_time` (Due Time). Optional extras: `notes`, `list` (a list name), `is_shared`.
6. **Get Dictionary Value** — key `message`, in *Contents of URL*.
7. **Show Notification** — the dictionary value from step 6.

**Both Format Date actions read the same variable from step 2.** That is the fix for the
2026-09-02 bug: the first recipe asked for a day and a time in two separate prompts, and
a `due_time` that failed to wire up left the field empty — which the endpoint then read
as "no time given" and quietly filled in with the default hour. One picker, formatted
twice, cannot disagree with itself or go missing. (The endpoint now refuses an empty
`due_time` outright as well; belt and braces.)

Steps 3 and 4 are not optional padding either: a raw *Ask for Input* date variable
renders as "September 3, 2026" when it lands in a text field, which the endpoint refuses.
Renaming both variables matters because Format Date names them all *Formatted Date* —
two identically named pills in the picker is how the wrong one gets chosen.

Step 7 is why every response carries `message`: a refused key, a bad date and a created
reminder all land in the same notification, so the shortcut never fails silently.

Then: *Add to Siri* — the shortcut's **name** is the phrase Siri listens for, so call it
"Add reminder" — and pin it to the Action Button, the Lock Screen or the home screen.
Deleting actions 2–4 **and their two JSON fields** gives a one-tap version that lands on
the account's default reminder hour. Delete the fields, don't just blank them: an empty
`due_date` or `due_time` is now a refusal, precisely so that a field which was *meant* to
carry something can never fall back to a default instead.

Tailscale has to be connected on the phone, same as for the widget.

A phone-readable version of this recipe is published as an Artifact:
<https://claude.ai/code/artifact/fc44d6c4-0395-47ef-bcf6-319b2fcdf3b4>.

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

### Authentication — the phone's one token

The `phone_token` column on `users` (renamed from `widget_token`; see the close-out),
resolved with the same non-short-circuiting `hash_equals` scan the widget feed has always
used. One key per phone, not one per feature: the widget's link and the Shortcut's header
carry the same string, and generating a new one in settings revokes both.

Resolution happens in middleware (`ResolveShortcutToken`) rather than in the controller,
so `$request->user()` is set the way the rest of the app expects it and the form request
can scope its rules to the owner. The token is read from the `X-Shortcut-Token` header
first, then from the request input (query or body) — the header is what the setup recipe
uses, so the key stays out of the server's access log; the input fallback is there
because the widget's link has always carried it in a query string, and it is the same
key, so the same paste has to work.

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

One "Your phone's key" panel on `settings/reminders`, with a single
`POST settings/reminders/phone-token` regenerate action behind one button. It shows the
one secret in the three fields its two surfaces need: the widget's whole ready-to-paste
feed link, the shortcut endpoint, and the key on its own — the recipe puts that in a
header, so it never has to be pasted as part of a URL.

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
