<?php

namespace App\Support;

use App\Models\Reminder;
use App\Models\ReminderAlert;
use App\Models\ReminderCompletion;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Notifications\ReminderPreAlertNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * The in-app record of everything that was sent: the `notifications` rows the
 * delivery engine writes through the `database` channel, turned into the feed
 * the /history page renders.
 *
 * Two rules this class exists to hold:
 *
 * 1. **The payload is the history, not the reminder.** Every entry reads its
 *    title and occurrence out of `ReminderDueNotification::toArray()`
 *    (`reminder_id`, `title`, `due_at` as ISO-8601 UTC), so an entry still
 *    renders — with the title it was sent under — after the reminder itself is
 *    deleted or renamed. The reminder is looked up only to offer a link to its
 *    edit surface; a miss is simply "deleted".
 *
 *    A **pre-alert** writes the same shape plus `kind => 'pre_alert'`,
 *    `fire_at` and `offset_minutes` (`ReminderPreAlertNotification`). An entry
 *    with no `kind` at all is a due notification and behaves exactly as it
 *    always has — that is the whole compatibility rule, and it is why the two
 *    are told apart by a payload key rather than by the row's `type`.
 * 2. **Days are local days.** Grouping happens on the *reader's* calendar
 *    (their timezone, or the app default — ARCHITECTURE.md §1 plus
 *    `User::timezone()`), exactly like TodayBoard buckets — a 23:30
 *    local send belongs to that local day even though its UTC date has already
 *    rolled over. Every string a page receives is formatted here, through
 *    ReminderPresenter; the client never does timezone math.
 *
 * The occurrence in the payload is byte-identical to the matching
 * `reminder_dispatches.due_at` (delivery-engine close-out), so history and the
 * dispatch log can always be joined on `(reminder_id, due_at)` — nothing here
 * needs that join, because the payload already carries everything the feed
 * shows, but the correspondence is deliberate and must be preserved.
 */
final class NotificationHistory
{
    /**
     * The notification types this feed shows — and the only ones whose unread
     * rows the badge counts and the page clears. Scoping all three to the same
     * list keeps them consistent: a future notification class cannot leave a
     * badge lit that visiting /history can never turn off.
     *
     * @var list<class-string>
     */
    public const TYPES = [
        ReminderDueNotification::class,
        ReminderPreAlertNotification::class,
    ];

    /**
     * How many entries the feed carries at most. The page is a single scroll
     * with no pagination, and pruning only reaches read entries older than 90
     * days, so a busy recurring reminder could otherwise grow it without
     * bound.
     */
    public const MAX_ENTRIES = 200;

    /**
     * How long a read entry is kept before `reminders:prune-notifications`
     * deletes it, in days. Unread entries are never pruned — they are the
     * pushes nobody has looked at yet.
     */
    public const PRUNE_AFTER_DAYS = 90;

    /**
     * A history. It holds no state of its own — every local day it groups on
     * belongs to the reader it is opened {@see openFor()}.
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * How many sent notifications this user has not seen yet — the number on
     * the nav badge, shared to every page by HandleInertiaRequests.
     */
    public static function unreadCountFor(User $user): int
    {
        return $user->unreadNotifications()->whereIn('type', self::TYPES)->count();
    }

    /**
     * Open the history: build the feed, *then* mark everything read.
     *
     * The order is the whole point and is why this is one method rather than
     * two the caller has to sequence. The unread flags are captured while they
     * are still true, so the visit that clears the badge is also the visit
     * that shows you what was new — and by the time Inertia resolves the
     * shared unread count (a closure, evaluated after the controller returns)
     * the badge already reads zero.
     *
     * `total` counts the entries in this window rather than the whole table;
     * `is_capped` is how the page knows older ones exist beyond it.
     *
     * @return array{
     *     days: list<array{key: string, label: string, entries: list<array<string, mixed>>}>,
     *     unread_count: int,
     *     total: int,
     *     max_entries: int,
     *     is_capped: bool,
     * }
     */
    public function openFor(User $user): array
    {
        // The feed is grouped and stamped on the *reader's* clock: two
        // household members in different timezones see the same sends filed
        // under their own local days.
        $timezone = $user->timezone();
        $presenter = ReminderPresenter::for($user);

        $notifications = $this->recent($user);
        $completions = $this->recentCompletions($user);
        $reminders = $this->presentedReminders($user, $presenter, $notifications, $completions);
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $unread = 0;
        $days = [];

        foreach ($notifications as $notification) {
            $entry = $this->entry($notification, $presenter, $reminders);

            if ($entry['is_unread'] === true) {
                $unread++;
            }

            // Filed under the occurrence's own day (its due date), not when
            // the push happened to go out — see the class-level note on why.
            $this->fileEntry($days, $entry, $this->occurredAt($notification)->setTimezone($timezone), $today);
        }

        foreach ($completions as $completion) {
            $entry = $this->completionEntry($completion, $presenter, $reminders);

            // Filed under the day it was actually completed — an activity
            // log reads by when you did the thing, not by whenever the
            // occurrence had originally been due.
            $this->fileEntry($days, $entry, $presenter->toLocal(CarbonImmutable::instance($completion->completed_at)), $today);
        }

        foreach ($days as &$day) {
            usort($day['entries'], fn (array $a, array $b): int => $b['sort_at'] <=> $a['sort_at']);

            $day['entries'] = array_map(
                fn (array $entry): array => array_diff_key($entry, ['sort_at' => true]),
                $day['entries'],
            );
        }

        unset($day);

        // String day keys ("2026-08-04") sort lexicographically the same way
        // they sort chronologically, so a plain reverse key-sort is enough —
        // no need to pull in a Collection for one comparison.
        krsort($days);
        $days = array_values($days);

        $this->markAllRead($user);

        return [
            'days' => $days,
            'unread_count' => $unread,
            'total' => $notifications->count() + $completions->count(),
            'max_entries' => self::MAX_ENTRIES,
            'is_capped' => $notifications->count() >= self::MAX_ENTRIES || $completions->count() >= self::MAX_ENTRIES,
        ];
    }

    /**
     * File one entry into its day bucket, keyed on the local day it belongs
     * under. `sort_at` is a bookkeeping field only — stripped before the
     * feed is returned — that lets entries from both sources be interleaved
     * newest-first once a day's bucket is complete.
     *
     * @param  array<string, array{key: string, label: string, entries: list<array<string, mixed>>}>  $days
     * @param  array<string, mixed>  $entry
     */
    private function fileEntry(array &$days, array $entry, CarbonInterface $local, CarbonImmutable $today): void
    {
        $key = $local->format('Y-m-d');

        $days[$key] ??= [
            'key' => $key,
            'label' => $this->dayLabel($local, $today),
            'entries' => [],
        ];

        $days[$key]['entries'][] = [...$entry, 'sort_at' => $local->timestamp];
    }

    /**
     * Mark every sent notification this user has read.
     *
     * The bulk equivalent of `DatabaseNotification::markAsRead()` — one
     * statement rather than one model per row, which matters on a feed that is
     * allowed to be hundreds of entries long.
     */
    public function markAllRead(User $user): int
    {
        return $user->unreadNotifications()
            ->whereIn('type', self::TYPES)
            ->update(['read_at' => Carbon::now()]);
    }

    /**
     * The newest entries first — the feed order. `notifications()` already
     * sorts by `created_at desc`; the cap is applied on that order so the page
     * always shows the most recent window.
     *
     * @return EloquentCollection<int, DatabaseNotification>
     */
    private function recent(User $user): EloquentCollection
    {
        /** @var EloquentCollection<int, DatabaseNotification> $notifications */
        $notifications = $user->notifications()
            ->whereIn('type', self::TYPES)
            ->limit(self::MAX_ENTRIES)
            ->get();

        return $notifications;
    }

    /**
     * The completions this user may see, newest first, capped the same way
     * {@see recent()} caps notifications.
     *
     * @return EloquentCollection<int, ReminderCompletion>
     */
    private function recentCompletions(User $user): EloquentCollection
    {
        /** @var EloquentCollection<int, ReminderCompletion> $completions */
        $completions = ReminderCompletion::query()
            ->visibleTo($user)
            ->latest('completed_at')
            ->limit(self::MAX_ENTRIES)
            ->get();

        return $completions;
    }

    /**
     * One presented reminder per id still reachable by this viewer, keyed by
     * id — the link target for entries whose reminder survives.
     *
     * Visibility goes through the same `visibleTo` scope every other surface
     * uses (shared-reminders close-out), so a reminder that has been deleted
     * *or* is no longer shared with you reads the same way: gone. Presenting
     * once per reminder rather than once per entry matters because a recurring
     * reminder owns many entries.
     *
     * @param  EloquentCollection<int, DatabaseNotification>  $notifications
     * @param  EloquentCollection<int, ReminderCompletion>  $completions
     * @return array<int, array<string, mixed>>
     */
    private function presentedReminders(
        User $user,
        ReminderPresenter $presenter,
        EloquentCollection $notifications,
        EloquentCollection $completions,
    ): array {
        $ids = [];

        foreach ($notifications as $notification) {
            $id = $this->reminderId($notification);

            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        foreach ($completions as $completion) {
            if ($completion->reminder_id !== null) {
                $ids[$completion->reminder_id] = $completion->reminder_id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $presented = [];

        foreach (Reminder::query()->visibleTo($user)->with([
            'user',
            'list',
            'alerts',
            'filings' => fn ($query) => $query->where('user_id', $user->id)->with('list'),
        ])->whereKey($ids)->get() as $reminder) {
            $presented[$reminder->id] = $presenter->present($reminder, $user);
        }

        return $presented;
    }

    /**
     * One line of the feed.
     *
     * @param  array<int, array<string, mixed>>  $reminders
     * @return array{
     *     id: string,
     *     type: 'sent',
     *     kind: 'pre_alert'|null,
     *     title: string,
     *     time_label: string,
     *     due_label: string,
     *     sent_relative: string,
     *     is_unread: bool,
     *     reminder: array<string, mixed>|null,
     * }
     */
    private function entry(
        DatabaseNotification $notification,
        ReminderPresenter $presenter,
        array $reminders,
    ): array {
        $occurredAt = $this->occurredAt($notification);
        $reminderId = $this->reminderId($notification);
        $isPreAlert = $this->payloadString($notification, 'kind') === 'pre_alert';

        return [
            'id' => (string) $notification->getKey(),
            'type' => 'sent',
            // Null for a due notification — every entry written before
            // pre-alerts shipped, and every one written since by the due
            // pass. The page may branch on it; it does not have to.
            'kind' => $isPreAlert ? 'pre_alert' : null,
            // The title as it was sent, not as the reminder reads now — the
            // history is a record of what went out.
            'title' => $this->payloadString($notification, 'title') ?? 'Reminder',
            'time_label' => $presenter->toLocal($occurredAt)->format('g:i A'),
            'due_label' => $isPreAlert
                ? $this->preAlertLabel($notification, $presenter)
                : $presenter->label($occurredAt),
            'sent_relative' => $this->sentAt($notification)->diffForHumans(),
            'is_unread' => $notification->read_at === null,
            // Null is the "deleted" state the page renders instead of a link.
            'reminder' => $reminderId === null ? null : ($reminders[$reminderId] ?? null),
        ];
    }

    /**
     * How a pre-alert entry says what it was: "Alerted 1 hour before Wed,
     * Aug 5, 3:00 PM".
     *
     * It goes in `due_label` rather than a field of its own so the existing
     * feed renders it without a change: the second line of every row already
     * reads "<when it was sent> · <due_label>", and this simply makes the
     * second half tell the truth about which of the two notifications it was.
     *
     * The moment is the payload's `due_at`, which for a pre-alert is the
     * **raw** occurrence it was anchored to — so `fire_at + offset` really is
     * this moment, and the sentence is not a lie about the gap.
     */
    private function preAlertLabel(DatabaseNotification $notification, ReminderPresenter $presenter): string
    {
        $dueAt = $this->payloadString($notification, 'due_at');
        $moment = $dueAt === null
            ? $this->sentAt($notification)
            : CarbonImmutable::parse($dueAt)->utc();

        $offset = $this->payloadInt($notification, 'offset_minutes');

        if ($offset === null) {
            return 'Alert · '.$presenter->label($moment);
        }

        return 'Alerted '.ReminderAlert::horizonLabel($offset).' before '.$presenter->label($moment);
    }

    /**
     * One line of the feed for a completed reminder — the sibling of
     * {@see entry()}. `time_label`/`sent_relative` describe *when it was
     * completed*, not the occurrence's original due moment — that moment is
     * still available via `due_label`, same as a sent entry.
     *
     * @param  array<int, array<string, mixed>>  $reminders
     * @return array{
     *     id: string,
     *     type: 'completed',
     *     kind: null,
     *     title: string,
     *     time_label: string,
     *     due_label: string,
     *     sent_relative: string,
     *     is_unread: bool,
     *     reminder: array<string, mixed>|null,
     * }
     */
    private function completionEntry(
        ReminderCompletion $completion,
        ReminderPresenter $presenter,
        array $reminders,
    ): array {
        $completedAt = CarbonImmutable::instance($completion->completed_at)->utc();
        $occurredAt = CarbonImmutable::instance($completion->occurred_at)->utc();

        return [
            'id' => 'completed-'.$completion->getKey(),
            'type' => 'completed',
            'kind' => null,
            'title' => $completion->title,
            'time_label' => $presenter->toLocal($completedAt)->format('g:i A'),
            'due_label' => $presenter->label($occurredAt),
            'sent_relative' => $completedAt->diffForHumans(),
            'is_unread' => false,
            'reminder' => $completion->reminder_id === null ? null : ($reminders[$completion->reminder_id] ?? null),
        ];
    }

    /**
     * The moment this entry is filed and stamped under, in UTC.
     *
     * For a due notification that is `due_at` — the occurrence — falling back
     * to when the row was written if a payload ever lacks it.
     *
     * For a **pre-alert** it is `fire_at`, when the alert actually went out,
     * not the due moment it was warning about. A "1 week before" alert
     * otherwise files itself under a day it has nothing to do with, and its
     * time stamp would name an hour nothing happened at.
     */
    private function occurredAt(DatabaseNotification $notification): CarbonImmutable
    {
        $moment = $this->payloadString($notification, 'kind') === 'pre_alert'
            ? $this->payloadString($notification, 'fire_at')
            : $this->payloadString($notification, 'due_at');

        return $moment === null
            ? $this->sentAt($notification)
            : CarbonImmutable::parse($moment)->utc();
    }

    /**
     * When the notification row was written — which is when the push went out.
     */
    private function sentAt(DatabaseNotification $notification): CarbonImmutable
    {
        return CarbonImmutable::instance($notification->created_at ?? Carbon::now())->utc();
    }

    /**
     * The reminder this entry points at, or null when the payload has no
     * usable id.
     */
    private function reminderId(DatabaseNotification $notification): ?int
    {
        $data = $notification->data;

        if (! isset($data['reminder_id']) || ! is_numeric($data['reminder_id'])) {
            return null;
        }

        return (int) $data['reminder_id'];
    }

    /**
     * A string field out of the stored payload, or null when it is missing or
     * the wrong shape. The payload is JSON written by an older release of this
     * app — it is read defensively, never assumed.
     */
    private function payloadString(DatabaseNotification $notification, string $key): ?string
    {
        $data = $notification->data;

        if (! isset($data[$key]) || ! is_string($data[$key])) {
            return null;
        }

        return $data[$key];
    }

    /**
     * An integer field out of the stored payload, read as defensively as
     * {@see payloadString()} reads a string one.
     */
    private function payloadInt(DatabaseNotification $notification, string $key): ?int
    {
        $data = $notification->data;

        if (! isset($data[$key]) || ! is_numeric($data[$key])) {
            return null;
        }

        return (int) $data[$key];
    }

    /**
     * "Today", "Yesterday", "Mon, Aug 3" — and the year as well once the day
     * is old enough for it to be ambiguous.
     */
    private function dayLabel(CarbonInterface $day, CarbonImmutable $today): string
    {
        if ($day->isSameDay($today)) {
            return 'Today';
        }

        if ($day->isSameDay($today->subDay())) {
            return 'Yesterday';
        }

        return $day->year === $today->year
            ? $day->format('D, M j')
            : $day->format('D, M j, Y');
    }
}
