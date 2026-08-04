# Shared Reminders (Household)

**Status:** ✅ **Implemented — 2026-08-03** (manual two-browser push verification still owed)

## Close-out

**Deviations:** `users.household_id` has **no DB-level FK** (adding one to an existing
SQLite table forces a full table rebuild) — integrity is held by the column being
non-fillable with `HouseholdController` actions as the only writers, plus
last-member-out deleting the empty household. The share toggle is the vendored
`Checkbox` (no switch component exists in this kit or any sibling); because an unchecked
checkbox posts nothing, `ReminderRequest` reads `$this->boolean('is_shared')` — don't
"fix" that to the validated set or un-sharing breaks. "Already in a household" is a 403
(`authorize()`), wrong invite code is a validation error. Either member can toggle
`is_shared` on a shared reminder (consistent with delete being allowed).

**Things later work must know:**
- Visibility rule lives in `Reminder::visibleTo(User)` (query) and
  `Reminder::isVisibleTo(User)` (row) — the policy just delegates to the latter, so
  future abilities (complete/snooze) inherit the household rule automatically by
  delegating the same way.
- `Reminder::recipients()`: private → owner; shared → all household members (falls back
  to owner if they've left). Dispatch bookkeeping is unchanged: one row per occurrence,
  `Notification::send()` fans out.
- Invite codes are 10-char mixed-case base62, compared byte-for-byte in PHP (SQLite
  collation would be case-insensitive — don't move the comparison into SQL).
- Nothing is rewritten on join/leave; visibility derives from current membership.
- `ReminderPresenter::formDefaults()` takes a `User` (for `can_share`); `present()`
  emits `is_shared`, `is_mine`, `owner_label` pre-assembled — keep name/zone logic out
  of Vue.
- Suite at close: 131 tests / 558 assertions. Manual E2E owed: two subscribed browsers,
  shared reminder pushes to both, private only to its owner.

Two accounts (the owner and their wife) share one household. A **private** reminder
behaves exactly as today: visible to and pushed to its owner only — on whichever account
created it. A **shared** reminder is visible to both household members and **pushed to
both accounts' devices** when due.

## Model

- `households` table: `id`, `name`, `invite_code` (unique random string), timestamps.
- `users.household_id` nullable FK (new migration — nullable column addition is
  SQLite-safe).
- `reminders.is_shared` boolean default `false` (new migration).

One household per user, max two members enforced nowhere structurally (a third member
would just work — fine). Reminders keep their `user_id` owner; sharing never transfers
ownership.

## Access rules

- **Visibility**: private → owner only (current behavior, unchanged). Shared → every
  member of the owner's household. All list queries (reminders index, Today view,
  widget feed when it ships) change from `user_id = me` to
  `user_id = me OR (is_shared AND owner in my household)` — centralize this in a single
  scope (`Reminder::visibleTo($user)`) so every surface uses the same rule.
- **Editing/completing/snoozing**: any household member can act on a shared reminder —
  update `ReminderPolicy` from owner-only to `visibleTo`-equivalent. Complete/snooze are
  row-level: one member completing or snoozing a shared reminder completes/snoozes it
  for both (it's one reminder, not two copies).
- Shared rows show a small badge (lucide `users`) and "by {owner first name}" when the
  viewer isn't the owner.

## Delivery

`SendDueReminders` recipient resolution: private → the owner; shared → all household
members. Each recipient gets the full notification (web push to their devices +
their own `database` channel entry). Dispatch bookkeeping stays per-reminder (one
`reminder_dispatches` row per occurrence, not per recipient) — the send-once guarantee
is about the occurrence, and `Notification::send($recipients, ...)` fans out from there.

## Household linking UI

Settings → new "Household" section:

- No household: **Create household** (generates invite code, shows it) or **Join** (enter
  the partner's code).
- In a household: show members, the invite code (with regenerate), and **Leave**.
  Leaving reverts your shared reminders to private? No — your shared reminders simply
  stop being visible to the other member (visibility is derived from current household
  membership, so no data migration on leave/join).

## UI touchpoints

- Reminder form sheet: a "Shared with household" switch (hidden entirely when the user
  has no household).
- Today view + reminders index + (later) widget feed: use the central visibility scope;
  show the shared badge.

## Acceptance criteria

- Pest: visibility scope (owner sees own + household-shared; outsider sees neither),
  policy (partner can edit/complete shared, cannot touch private), delivery fan-out
  (shared reminder due → notifications to both members, one dispatch row; private → owner
  only), join/leave flows, invite-code validation.
- Both accounts subscribed to push locally: a due shared reminder pushes to both; a
  private one pushes only to its owner.
- 375px layout for the household settings section and shared badges.

## Dependencies / sequencing

After delivery-engine (it modifies recipient resolution there). Before or alongside
recurrence — recurring shared reminders need no special handling (advancing `due_at` is
row-level).

## Open questions

1. Should snooze on a shared reminder be per-user instead of shared? (Spec says shared —
   simpler, and "one of us handled it" matches household use. Revisit if it feels wrong.)
2. Default the "shared" switch on for new reminders once a household exists, or off?
   (Spec assumes off — private by default.)
