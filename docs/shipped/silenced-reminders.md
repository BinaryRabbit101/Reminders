# Silenced Reminders

**Status:** ✅ **Implemented — 2026-08-24**

## Close-out

**Deviations:** A `SilencedBadge.vue` row glyph was added beyond the bare toggle. The
auto-complete spec explicitly needed no list-surface glyph; this one does, because
silence's entire failure mode is "why didn't my phone go off?" and the edit sheet would
otherwise be the only place the state is visible. Two feature-test classes
(`SilencedReminderDispatchTest`, `SilencedReminderCrudTest`), mirroring the
pre-alerts/auto-complete split. Dusk asserts the checkbox via `data-state`
(`assertAttribute`), for the same reka-ui reason auto-complete does.

**Amended same day — the row menu.** The edit-sheet checkbox alone was not findable:
silencing is something you decide *about a row in front of you*, not something you go
into a form to arrange. A one-tap Silence/Unsilence item now sits on the row's snooze
menu (`ReminderSnoozeMenu.vue`), below the presets, on both the Today board and the
all-reminders page. The checkbox stays — it is how you decide at the moment you create
the reminder. The menu's trigger `aria-label` widened from "Snooze {title}" to "Snooze or
silence {title}" to match what it now opens.

**Things later work must know:**
- Silence sits **inside the send step**, exactly where quiet hours do, and answers a
  different question: quiet hours decide *when* a push lands, `is_silenced` says there is
  no push at all. Claiming, stale-suppression, `sent_at` and auto-complete advancement
  are all untouched — if an assertion about `reminder_dispatches` or `due_at` starts
  failing because of this flag, the feature has grown past its remit.
- It short-circuits **ahead of** `splitByQuietHours()` and holds nothing. A `held_pushes`
  row is a promise to buzz later; a silenced reminder makes no such promise.
- It is the **reminder's** property, not the recipient's — the opposite of quiet hours.
  A shared silenced reminder is silent for the whole household.
- It covers that reminder's **pre-alerts** too. A "due in 1 hour" buzz for something that
  then goes off in silence would be the loudest part of a reminder the user asked to keep
  quiet.
- `HeldPush::isSuperseded()` reads it, so silencing a reminder while a push already sits
  in the overnight queue drops that push at release rather than letting a row written
  before the toggle outlive it.
- Unlike `auto_complete`, it is **not** a repeat field: it means the same thing on a
  one-off as on a series, so nothing normalises it away and turning a repeat off leaves
  it alone. It is shaped in `ReminderRequest::reminderAttributes()` next to `is_shared`,
  not in `recurrenceAttributes()`.
- The menu endpoint (`POST reminders/{reminder}/silence`) is a **toggle**, not a setter:
  it sends no desired state and the server flips the column, so a row rendered before
  someone else changed it cannot set it wrong. `Reminder::toggleSilence()` is the seam.
- It authorizes as `update`, like `restore()` does — it writes a column the form could
  reach anyway, so it needs no ability of its own, and it inherits the household rule
  from `Reminder::isVisibleTo()`. That inheritance is right rather than incidental:
  silence belongs to the reminder, so either member switching it off switches it off for
  both.
- There is deliberately **no signed twin** in `routes/notification-actions.php`. Silencing
  is not something a push notification offers, and such a link would outlive the
  occurrence that carried it.
- Silencing after an occurrence has already been claimed and sent does not un-ring it —
  it governs the next one. `toggleSilence()` moves nothing else: not `due_at`, not
  `snoozed_until`, not `completed_at`.
- Suite at close: 464 Pest tests / 1906 assertions, 28 Dusk tests / 119 assertions,
  Larastan clean, Pint clean.

A per-reminder toggle: when this reminder goes off, nobody's phone buzzes. It still
appears on the Today board, still writes its in-app notification record, and still counts
toward the unread badge — only the web-push half is dropped.

Read [../ARCHITECTURE.md](../ARCHITECTURE.md), [delivery-engine.md](delivery-engine.md)
and [settings-and-quiet-hours.md](settings-and-quiet-hours.md) first. Quiet hours already
established the split this feature reuses: `ReminderDueNotification::CHANNELS_IN_APP` is
the in-app-only send, and it exists precisely so a channel can be dropped without an
occurrence being suppressed.

## Model

- New column on `reminders`: `is_silenced` boolean default false. Added to the `Fillable`
  attribute, casts, and the model docblock.
- Meaningful on any reminder, one-off or repeating. Nothing normalises it — this is the
  deliberate difference from `auto_complete`.

## Engine behavior

In `SendDueReminders`, inside `notify()` and `notifyAlert()`, ahead of the quiet-hours
split:

```php
if ($reminder->is_silenced) {
    return $this->notifySilently($reminder, new ReminderDueNotification(
        $reminder, $occurredAt, ReminderDueNotification::CHANNELS_IN_APP,
    ));
}
```

`notifySilently()` sends the in-app half to every recipient and returns zero held pushes.

`HeldPush::isSuperseded()` and `isPreAlertSuperseded()` both gained an `is_silenced`
check, so a push held before the toggle was ticked is dropped at release.

## Form & presentation

- `ReminderRequest`: `is_silenced` → `['nullable', 'boolean']`, read with `boolean()` in
  `reminderAttributes()` so an unticked checkbox (which posts nothing) reads as off.
- `ReminderFormSheet.vue`: a checkbox directly under the pre-alert chips, because it
  governs them too. Always rendered, so it stays uncontrolled on `:default-value` the way
  `is_shared` does rather than needing `auto_complete`'s local ref.
- `ReminderSnoozeMenu.vue`: a Silence/Unsilence item below the snooze presets — the
  primary surface, and the one users actually find. One item with two labels read off
  `is_silenced`, posting through `useReminderActions().toggleSilence()`.
- `SilencedBadge.vue`: a crossed-out bell glyph, shaped like `AlertsBadge.vue` and placed
  beside it on both the Today card and the all-reminders row.
- `ReminderPresenter::present()` + `formDefaults()` + `resources/js/types/reminders.ts`
  carry `is_silenced: boolean`.

## Acceptance criteria

Class-based PHPUnit style, matching the suite. All met at close.

- Feature (engine, frozen time): a silenced reminder sends `CHANNELS_IN_APP` and no push;
  the occurrence is still claimed and marked sent, and a second sweep is a no-op; it
  still shows in Overdue; an unsilenced reminder is unchanged; a shared silenced reminder
  is silent for every household member; its pre-alert is silent and still claimed; quiet
  hours hold nothing for it; silencing overnight drops an already-held push and an
  already-held pre-alert; it composes with auto-complete; a stale silenced occurrence is
  suppressed like any other.
- Feature (CRUD): round-trips through store and update in both directions; kept on a
  one-off; survives turning a repeat off; non-boolean rejected; presenter and form
  defaults carry it.
- Feature (menu endpoint): toggles both ways; flashes a toast naming the direction; moves
  no other column; guests redirect to login and strangers get a 403; a household member
  can silence a shared reminder for both but not a private one.
- Dusk: the checkbox is present for a one-off (where auto-complete's is not), ticking it
  saves and draws the row glyph, the sheet reopens on the saved state and unticking
  takes, the row menu silences and unsilences in one tap with the item's label following
  the row, an ordinary reminder carries no glyph.
