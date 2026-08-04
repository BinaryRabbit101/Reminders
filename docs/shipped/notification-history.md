# Notification History

**Status:** ✅ **Implemented — 2026-08-03**

## Close-out

**Deviations:** Feed capped at `NotificationHistory::MAX_ENTRIES = 200` (no pagination
in spec; pruning alone can't bound an unread-heavy table). Surviving entries open the
`ReminderFormSheet` in place rather than linking away.

**Things later work must know:**
- The unread count is shared via `HandleInertiaRequests::share()` **as a closure** —
  load-bearing: it resolves at response-render time, so the visit that marks entries
  read reports 0 in the same response. Don't "simplify" it to a plain value.
- Feed, badge, and mark-all-read are scoped to `ReminderDueNotification::class`
  (`NotificationHistory::TYPE`); a future notification type needs its own surfacing or
  it would light a badge `/history` never clears. The prune command spans the whole
  table.
- Prune: `reminders:prune-notifications`, daily, deletes read entries older than 90 days
  by `created_at` (reading never restarts the clock; unread never pruned).
- A notification row exists only for real sends — never for stale-suppressed claims;
  `(reminder_id, due_at)` still joins to `reminder_dispatches` byte-identically.
- History's sidebar item sits outside `NavMain` so `SidebarMenuBadge` can anchor to it.
- Suite at close (post-consolidation): 247 tests / 1028 assertions, 0 skipped.

An in-app record of everything that was sent, so a dismissed push is never lost.

## Behavior

- The delivery engine already writes to the `database` notification channel
  (`ReminderDueNotification::via()` includes `'database'`) — this spec surfaces it.
- `/history` page: reverse-chronological feed of sent notifications with local-time
  stamps, grouped by day, linking each entry to its reminder (or showing "deleted" if
  gone). Unread entries visually distinct; opening the page marks all read
  (`markAsRead()`).
- A subtle unread-count badge on the app's nav (sidebar/header from the starter layout).
- Prune old read entries after 90 days via a `->daily()` schedule entry (timezone
  irrelevant — chain nothing, per ARCHITECTURE.md §1 only time-of-day schedules need TZ).

## Implementation

- Publish the standard Laravel `notifications` table migration if not present.
- `toDatabase()`/`toArray()` on `ReminderDueNotification`: store `reminder_id`, `title`,
  `due_at` so history survives reminder deletion.
- LittlePocketMeseum uses the same dual-channel convention — check its notification
  classes for the `toArray` shape.

## Acceptance criteria

- Sent reminders appear in `/history`; badge counts unread; visiting clears it; entries
  for deleted reminders still render. Pruning removes only read entries older than 90
  days. Pest coverage; 375px layout.
