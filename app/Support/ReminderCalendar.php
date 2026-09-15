<?php

namespace App\Support;

use App\Models\Reminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lays the reminders the list view is already showing out on a month grid.
 *
 * Two things make this more than a `group by date`:
 *
 * - **Local days, again.** Rows are stored in UTC, so which *cell* a reminder
 *   lands in is decided on the viewer's calendar and nowhere else — the same
 *   rule {@see TodayBoard} exists to enforce. A reminder due 23:30 local
 *   belongs to that local day even though its UTC date has already rolled.
 * - **Projections.** A repeating reminder only stores the occurrence it is
 *   *currently* on, so a plain grid of `due_at` would leave a weekly chore
 *   showing up once a month and the calendar would look empty. Every further
 *   occurrence the rule produces inside the visible grid is drawn as well,
 *   flagged `is_projected` — they are computed, not stored, so nothing can be
 *   ticked, snoozed or deleted on one (there is no row there yet). The client
 *   renders them faintly for exactly that reason.
 *
 * The grid always runs whole weeks, Sunday to Saturday, so the leading and
 * trailing cells belong to the neighbouring months (`in_month` is false for
 * those). Reminders that fall in them are shown: the week is on screen, and
 * hiding a Monday chore because the month happens to start on Wednesday would
 * be a lie about that week.
 */
final class ReminderCalendar
{
    /**
     * How many occurrences a single repeating reminder may contribute to one
     * grid before the projection stops. Six weeks of a daily reminder is 42;
     * the cap is here so a pathological rule (a series that stops advancing,
     * a rule far denser than the grid) can never spin the page out.
     */
    public const MAX_PROJECTIONS = 60;

    /**
     * A calendar. Like the other presenters here it holds no state of its
     * own — the local calendar it draws belongs to whoever it is built
     * {@see for()}.
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * Build the month grid for a user.
     *
     * The reminders are handed in rather than queried: the calendar and the
     * list are two views of **the same filtered set** (the list chip, the
     * completed toggle), and re-querying here is how those two would quietly
     * drift apart. The caller passes models, not presented arrays, because
     * projecting a series needs the rule columns.
     *
     * `$month` is a local `Y-m`; anything else — a stale link, a hand-typed
     * URL — falls back to the month the viewer is currently in rather than
     * 404-ing, the same forgiveness the list filter shows an id that does not
     * resolve.
     *
     * @param  Collection<int, Reminder>  $reminders
     * @return array{
     *     month: string,
     *     month_label: string,
     *     prev_month: string,
     *     next_month: string,
     *     current_month: string,
     *     today: string,
     *     weekdays: list<string>,
     *     weeks: list<list<array{
     *         date: string,
     *         day: int,
     *         label: string,
     *         in_month: bool,
     *         is_today: bool,
     *         is_weekend: bool,
     *         entries: list<array<string, mixed>>,
     *     }>>,
     * }
     */
    public function for(User $user, Collection $reminders, ?string $month = null, ?DateTimeInterface $now = null): array
    {
        $timezone = $user->timezone();
        $presenter = ReminderPresenter::for($user);
        $now = CarbonImmutable::parse($now ?? Carbon::now())->utc();
        $today = $now->setTimezone($timezone);

        $first = $this->firstOfMonth($month, $today);
        $gridStart = $first->startOfWeek(CarbonInterface::SUNDAY);
        $gridEnd = $first->endOfMonth()->endOfWeek(CarbonInterface::SATURDAY);

        $days = [];

        for ($day = $gridStart; $day->lessThanOrEqualTo($gridEnd); $day = $day->addDay()) {
            $days[$day->format('Y-m-d')] = [
                'date' => $day->format('Y-m-d'),
                'day' => $day->day,
                // The heading the selected-day panel wears, assembled here
                // like every other date string the client renders.
                'label' => $day->format('l, F j'),
                'in_month' => $day->month === $first->month,
                'is_today' => $day->isSameDay($today),
                'is_weekend' => $day->isWeekend(),
                'entries' => [],
            ];
        }

        foreach ($reminders as $reminder) {
            foreach ($this->occurrences($reminder, $gridStart, $gridEnd) as [$moment, $isProjected]) {
                $key = $moment->setTimezone($timezone)->format('Y-m-d');

                // An occurrence can only miss the grid by a rounding of the
                // window edges; if it does, it belongs to a month nobody is
                // looking at.
                if (! isset($days[$key])) {
                    continue;
                }

                $days[$key]['entries'][] = $this->entry($reminder, $moment, $isProjected, $presenter, $user, $now);
            }
        }

        return [
            'month' => $first->format('Y-m'),
            'month_label' => $first->format('F Y'),
            'prev_month' => $first->subMonth()->format('Y-m'),
            'next_month' => $first->addMonth()->format('Y-m'),
            // Which month "Today" jumps to — the client only offers the
            // button while you are looking at some other month.
            'current_month' => $today->format('Y-m'),
            'today' => $today->format('Y-m-d'),
            'weekdays' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            'weeks' => $this->weeks($days),
        ];
    }

    /**
     * The first of the month being drawn, in the viewer's timezone.
     *
     * Validated by shape rather than by parsing: `CarbonImmutable::parse()`
     * happily accepts a great deal that is not a month, and a bad value here
     * should land on the current month, not throw.
     */
    private function firstOfMonth(?string $month, CarbonImmutable $today): CarbonImmutable
    {
        if ($month === null || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $parts) !== 1) {
            return $today->startOfMonth();
        }

        return $today->startOfMonth()
            ->setDate((int) $parts[1], (int) $parts[2], 1)
            ->startOfDay();
    }

    /**
     * Every moment this reminder occupies inside the visible grid, each
     * flagged with whether it is the stored occurrence or a projection.
     *
     * The stored one is placed at `effectiveDueAt()` — a snooze moves the
     * occurrence, and a calendar's job is to say when something will actually
     * go off. Projections step from `due_at` instead, because a snooze moves
     * one occurrence and never the schedule
     * ({@see Reminder::advanceOrComplete()}).
     *
     * @return list<array{0: CarbonImmutable, 1: bool}>
     */
    private function occurrences(Reminder $reminder, CarbonImmutable $gridStart, CarbonImmutable $gridEnd): array
    {
        $windowStart = $gridStart->utc();
        $windowEnd = $gridEnd->utc();
        $found = [];

        $stored = $reminder->effectiveDueAt();

        if ($stored->betweenIncluded($windowStart, $windowEnd)) {
            $found[] = [$stored, false];
        }

        $rule = $reminder->recurrenceRule();

        if ($rule === null) {
            return $found;
        }

        // The owner's clock, not the viewer's: a series that runs at 9:00
        // keeps running at *its owner's* 9:00 even when a household member is
        // the one looking at it ({@see RecurrenceCalculator::for()}).
        $calculator = RecurrenceCalculator::for($reminder->user);
        $seriesAt = CarbonImmutable::instance($reminder->due_at)->utc();

        // Jumping straight to the first occurrence at or after the window is
        // what keeps browsing a year ahead cheap — stepping one occurrence at
        // a time from a daily series' current position would never get there.
        $occurrence = $calculator->nextAfter($rule, $seriesAt, $windowStart->subSecond());

        for ($step = 0; $step < self::MAX_PROJECTIONS; $step++) {
            if ($occurrence === null || $occurrence->greaterThan($windowEnd)) {
                break;
            }

            // `nextAfter()` settles for what it has once its own step cap is
            // hit, so an occurrence before the window is possible here for an
            // absurdly long-running series; it is simply not on this grid.
            if ($occurrence->greaterThanOrEqualTo($windowStart)) {
                $found[] = [$occurrence, true];
            }

            $occurrence = $calculator->next($rule, $occurrence);
        }

        return $found;
    }

    /**
     * One reminder as it reads in a day cell.
     *
     * Deliberately not the full {@see ReminderPresenter::present()} payload:
     * a cell shows a dot, a time and a title, and the page already carries
     * every row in full for the panel underneath (the client joins on
     * `reminder_id`). A projection has no row to join to — it is drawn from
     * these fields alone.
     *
     * @return array<string, mixed>
     */
    private function entry(
        Reminder $reminder,
        CarbonImmutable $moment,
        bool $isProjected,
        ReminderPresenter $presenter,
        User $viewer,
        CarbonImmutable $now,
    ): array {
        $completed = $reminder->completed_at !== null;
        // Same rule as everywhere else: a snooze that has already run out is
        // not a snooze, it is an overdue reminder. Measured against the grid's
        // own `$now` rather than `isFuture()`, so every "is it past?" question
        // on one grid is answered by one clock.
        $snoozed = $reminder->snoozed_until !== null && $now->lessThan($reminder->snoozed_until);
        $list = $reminder->listFor($viewer);

        return [
            // Unique per cell — the same reminder appears on many days once
            // it repeats, so its id alone will not do as a `:key`.
            'key' => $reminder->id.'@'.$moment->toIso8601String(),
            'reminder_id' => $reminder->id,
            'title' => $reminder->title,
            'at' => $moment->toIso8601String(),
            'time_label' => $presenter->toLocal($moment)->format('g:i A'),
            'is_projected' => $isProjected,
            // A projection is a future occurrence of a series: it cannot have
            // been completed, snoozed or missed, whatever state the stored
            // occurrence happens to be in.
            'is_completed' => ! $isProjected && $completed,
            'is_overdue' => ! $isProjected && ! $completed && ! $snoozed && $moment->lessThan($now),
            'is_snoozed' => ! $isProjected && $snoozed,
            'is_shared' => $reminder->is_shared,
            'is_mine' => $viewer->id === $reminder->user_id,
            'is_recurring' => $reminder->isRecurring(),
            // The viewer's own filing, like every other surface — the dot on
            // an entry is their list's colour, never the owner's.
            'color_hex' => $list?->paletteColor()->hex(),
        ];
    }

    /**
     * Sort each day's entries and chunk the flat run of days into weeks.
     *
     * Sorted by moment, then title, so two reminders set to the same time
     * keep a stable order instead of shuffling from render to render.
     *
     * @param  array<string, array{
     *     date: string,
     *     day: int,
     *     label: string,
     *     in_month: bool,
     *     is_today: bool,
     *     is_weekend: bool,
     *     entries: list<array<string, mixed>>,
     * }>  $days
     * @return list<list<array{
     *     date: string,
     *     day: int,
     *     label: string,
     *     in_month: bool,
     *     is_today: bool,
     *     is_weekend: bool,
     *     entries: list<array<string, mixed>>,
     * }>>
     */
    private function weeks(array $days): array
    {
        foreach ($days as $date => $day) {
            /** @var list<array<string, mixed>> $entries */
            $entries = $day['entries'];

            usort($entries, fn (array $a, array $b): int => [$a['at'], $a['title']] <=> [$b['at'], $b['title']]);

            $days[$date]['entries'] = $entries;
        }

        return array_map(array_values(...), array_chunk(array_values($days), 7));
    }
}
