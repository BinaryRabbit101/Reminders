<?php

namespace App\Support;

use App\Models\Reminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * What the iPhone home-screen widget shows: a glance at what needs attention.
 *
 * The widget does **no** timezone math and assembles **no** strings — every
 * time in this payload is already spelled the way it should appear, on the
 * token holder's own clock ({@see User::timezone()}). That is the same rule
 * the Inertia pages live under (today-view close-out), and it matters more
 * here, not less: Scriptable runs on the phone's locale and would happily
 * render a UTC instant as a plausible-looking wrong time.
 *
 * Who the feed is allowed to show comes from `Reminder::visibleTo()` — the
 * one visibility rule every surface goes through — so a reminder shared by
 * the other household member appears here exactly as it does on the board.
 */
final class WidgetFeed
{
    /**
     * How many rows the attention list carries.
     *
     * A medium widget has room for about this many before text starts
     * shrinking; `overdue_count` and `pending_total` are what tell the reader
     * there is more behind it.
     */
    public const MAX_ROWS = 6;

    /**
     * How far ahead a day gets a weekday name rather than a date, in local
     * days. Past a week, "Fri" stops meaning *which* Friday.
     */
    private const NAMED_DAY_WINDOW = 7;

    /**
     * A feed. Stateless — the local calendar it works on belongs to whoever
     * it is built {@see for()}, exactly like {@see TodayBoard}.
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * Build the feed for the account whose token was presented.
     *
     * The shape is fixed by the scriptable-widget spec, with one addition:
     * each row carries `is_overdue`, because the widget paints overdue rows
     * red and the count alone cannot tell it which ones they are.
     *
     * `today` is the **attention list**, not literally today's calendar: it
     * is everything already overdue followed by everything still to come
     * before local midnight, soonest first, capped at {@see MAX_ROWS}. That
     * ordering is the whole point of the widget — the thing you are late for
     * belongs above the thing you are not.
     *
     * `upcoming` only exists to spend row budget `today` didn't use — a quiet
     * day with two things due leaves four rows of blank space on a medium
     * widget otherwise. It never competes with `today` for room: the cap is
     * `MAX_ROWS - count(today)`, so attention-list rows always win the space
     * first, and a full attention list means an empty (not truncated)
     * `upcoming`.
     *
     * On an empty attention list that budget gets two rows poorer first: the
     * "All clear." placeholder stands where `today`'s rows would have been,
     * and the "UPCOMING" section heading appears above the list it labels.
     * Neither is a reminder, but both cost a line on the medium widget, and
     * counting only reminder rows against MAX_ROWS is what let a quiet day's
     * upcoming list run text past the widget's own frame.
     *
     * @return array{
     *     overdue_count: int,
     *     today: list<array{time: string, title: string, list_color: string|null, is_overdue: bool}>,
     *     upcoming: list<array{time: string, title: string, list_color: string|null}>,
     *     next_upcoming: array{when: string, title: string}|null,
     *     pending_total: int,
     *     open_url: string,
     * }
     */
    public function for(User $user, ?DateTimeInterface $now = null): array
    {
        $now = CarbonImmutable::parse($now ?? Carbon::now())->utc();
        $timezone = $user->timezone();
        $local = $now->setTimezone($timezone);
        $endOfToday = $local->endOfDay();

        $rows = [];

        foreach ($this->pendingThrough($user, $endOfToday) as $reminder) {
            // A snooze moves the moment, so a reminder snoozed to 3pm is a
            // 3pm row — the same definition the board buckets on and the
            // delivery engine sends on.
            $at = $reminder->effectiveDueAt();

            $rows[] = [
                'time' => $this->rowTime($at->setTimezone($timezone), $local),
                'title' => $reminder->title,
                'list_color' => $this->listColor($reminder, $user),
                'is_overdue' => $at < $now,
            ];
        }

        $upcoming = [];
        $spareRows = self::MAX_ROWS - count($rows);

        if (count($rows) === 0) {
            // "All clear." and the "UPCOMING" heading each take a line of
            // their own once there is nothing due today to show instead.
            $spareRows -= 2;
        }

        if ($spareRows > 0) {
            foreach ($this->upcomingAfter($user, $endOfToday, $spareRows) as $reminder) {
                $at = $reminder->effectiveDueAt();

                $upcoming[] = [
                    'time' => $this->rowTime($at->setTimezone($timezone), $local),
                    'title' => $reminder->title,
                    'list_color' => $this->listColor($reminder, $user),
                ];
            }
        }

        return [
            // Counted rather than tallied from the rows above: the list is
            // capped and the count is not, and "3 overdue" with two rows
            // showing is the whole reason the number is there.
            'overdue_count' => Reminder::query()
                ->visibleTo($user)
                ->pending()
                ->whereRaw(Reminder::EFFECTIVE_DUE_AT.' < ?', [$now->format('Y-m-d H:i:s')])
                ->count(),
            'today' => $rows,
            'upcoming' => $upcoming,
            'next_upcoming' => $this->nextUpcoming($user, $now, $local),
            'pending_total' => Reminder::query()->visibleTo($user)->pending()->count(),
            // Where a tap lands. `app.url` rather than the request's own host:
            // the feed is fetched over Tailscale by a background process, and
            // the URL has to be the one a browser should open, which is the
            // app's configured address (the same value signed notification
            // action URLs depend on — snooze-and-complete close-out).
            'open_url' => rtrim((string) config('app.url'), '/').'/today',
        ];
    }

    /**
     * The soonest pending reminder still ahead of now, whenever it falls.
     *
     * Deliberately *not* "the first one after today": the small widget shows
     * only this line, and a reminder due in two hours is the answer to "what
     * is next", not tomorrow's. It therefore overlaps the attention list on
     * purpose — the medium layout only falls back to it when there is
     * nothing left today to show.
     *
     * @return array{when: string, title: string}|null
     */
    private function nextUpcoming(User $user, CarbonImmutable $now, CarbonInterface $local): ?array
    {
        $next = Reminder::query()
            ->visibleTo($user)
            ->pending()
            ->whereRaw(Reminder::EFFECTIVE_DUE_AT.' > ?', [$now->format('Y-m-d H:i:s')])
            ->orderByRaw(Reminder::EFFECTIVE_DUE_AT.' asc')
            ->first();

        if ($next === null) {
            return null;
        }

        return [
            'when' => $this->whenLabel(
                $next->effectiveDueAt()->setTimezone($user->timezone()),
                $local,
            ),
            'title' => $next->title,
        ];
    }

    /**
     * Pending reminders that are either overdue or still to come today,
     * soonest first — the attention list's working set.
     *
     * The window's local end is converted to UTC here, because the only
     * comparison the database ever sees is UTC against UTC. There is no lower
     * bound: something a week late is still something you are late for.
     *
     * @return Collection<int, Reminder>
     */
    private function pendingThrough(User $user, CarbonInterface $endOfToday): Collection
    {
        return Reminder::query()
            ->visibleTo($user)
            ->with(['list', 'filings' => fn ($query) => $query->where('user_id', $user->id)->with('list')])
            ->pending()
            ->whereRaw(Reminder::EFFECTIVE_DUE_AT.' <= ?', [
                $endOfToday->copy()->utc()->format('Y-m-d H:i:s'),
            ])
            ->orderByRaw(Reminder::EFFECTIVE_DUE_AT.' asc')
            // Overdue rows can pile up without bound, so the cap is applied
            // in SQL: the rows are already in the order the widget wants
            // them, and everything past the sixth is a number, not a row.
            ->limit(self::MAX_ROWS)
            ->get();
    }

    /**
     * Pending reminders due after local midnight, soonest first — what fills
     * the attention list's leftover row budget.
     *
     * `$limit` is the caller's spare capacity, never {@see MAX_ROWS} itself:
     * this only ever spends room `today` left unused.
     *
     * @return Collection<int, Reminder>
     */
    private function upcomingAfter(User $user, CarbonInterface $endOfToday, int $limit): Collection
    {
        return Reminder::query()
            ->visibleTo($user)
            ->with(['list', 'filings' => fn ($query) => $query->where('user_id', $user->id)->with('list')])
            ->pending()
            ->whereRaw(Reminder::EFFECTIVE_DUE_AT.' > ?', [
                $endOfToday->copy()->utc()->format('Y-m-d H:i:s'),
            ])
            ->orderByRaw(Reminder::EFFECTIVE_DUE_AT.' asc')
            ->limit($limit)
            ->get();
    }

    /**
     * The swatch a row draws, or null.
     *
     * The viewer's own filing, same as the app: the owner's own list, or a
     * household member's independent co-filing of a shared reminder — see
     * {@see Reminder::listFor()}. Lists themselves stay
     * personal; what changed is that a co-filer now has one to draw here at
     * all, where previously this always returned null for anyone but the
     * owner.
     */
    private function listColor(Reminder $reminder, User $viewer): ?string
    {
        return $reminder->listFor($viewer)?->paletteColor()->hex();
    }

    /**
     * The left-hand column of a row: "9:00 AM" for anything on today's local
     * calendar, "Aug 1" for an overdue row from an earlier day.
     *
     * A bare time on a three-day-old row would read as *this* morning, which
     * is worse than saying nothing — so once a row is not from today, the day
     * is the more useful of the two facts and the one that gets the space.
     */
    private function rowTime(CarbonInterface $at, CarbonInterface $local): string
    {
        return $at->isSameDay($local) ? $at->format('g:i A') : $at->format('M j');
    }

    /**
     * How the next reminder's moment reads: "3:00 PM" today, "Tomorrow 9:00
     * AM", "Fri 9:00 AM" inside the week, "Aug 12, 9:00 AM" beyond it.
     */
    private function whenLabel(CarbonInterface $at, CarbonInterface $local): string
    {
        $time = $at->format('g:i A');

        if ($at->isSameDay($local)) {
            return $time;
        }

        if ($at->isSameDay($local->copy()->addDay())) {
            return 'Tomorrow '.$time;
        }

        // Whole local days apart, so a 23:00→01:00 pair is one day, not two
        // — the labels have to agree with the calendar the rows use.
        $days = $local->copy()->startOfDay()->diffInDays($at->copy()->startOfDay());

        return $days < self::NAMED_DAY_WINDOW
            ? $at->format('D g:i A')
            : $at->format('M j, g:i A');
    }
}
