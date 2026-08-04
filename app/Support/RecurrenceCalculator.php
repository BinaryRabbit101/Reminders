<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Works out when a repeating reminder next comes due.
 *
 * Every step is taken on the *local* calendar (`config('reminders.timezone')`)
 * and only converted back to UTC on the way out — ARCHITECTURE.md §1. That is
 * the whole point of this class: "every day at 9:00" means nine o'clock on the
 * wall clock, so adding a day must be a calendar operation in local time, not
 * 24 hours of elapsed UTC. Do it the other way round and every reminder drifts
 * by an hour twice a year.
 *
 * Pure: no database, no clock of its own — give it an occurrence and a rule
 * and it hands back the next occurrence.
 */
final class RecurrenceCalculator
{
    /**
     * How many occurrences {@see nextAfter()} will step through before it
     * gives up and settles for what it has. Only a long outage can push a
     * series this far behind; the cap is here so a pathological rule can
     * never spin forever.
     */
    private const MAX_STEPS = 1000;

    public function __construct(private readonly string $timezone) {}

    /**
     * A calculator for the app's configured display timezone.
     */
    public static function make(): self
    {
        return new self((string) config('reminders.timezone'));
    }

    /**
     * A calculator on one user's clock.
     *
     * Always the reminder **owner's**, never the acting user's: a series
     * belongs to whoever set it up, so "every day at 9:00" has to keep meaning
     * nine o'clock where they are — including when the scheduler advances it
     * with nobody logged in at all, and when a household member completes a
     * shared reminder from their own (possibly different) timezone.
     */
    public static function for(User $user): self
    {
        return new self($user->timezone());
    }

    /**
     * The occurrence that follows the given one, in UTC.
     *
     * Returns null when the rule's end date has been passed — the series is
     * over and the caller should complete the reminder.
     */
    public function next(RecurrenceRule $rule, DateTimeInterface $occurrence): ?CarbonImmutable
    {
        $local = CarbonImmutable::instance($occurrence)->setTimezone($this->timezone);

        $next = match ($rule->unit) {
            'day' => $local->addDays($rule->interval),
            'week' => $this->nextWeekly($local, $rule),
            // A year is twelve months so that "yearly on Feb 29" clamps the
            // same way "monthly on the 31st" does, through one code path.
            'month' => $this->nextMonthly($local, $rule, $rule->interval),
            default => $this->nextMonthly($local, $rule, $rule->interval * 12),
        };

        if ($this->hasEnded($next, $rule)) {
            return null;
        }

        return $next->utc();
    }

    /**
     * The first occurrence after `$from` that also lands strictly after
     * `$floor`, in UTC — or null when the series ends first.
     *
     * The floor is what keeps a series that fell behind (an outage, or a
     * snooze that outlived several occurrences) from crawling forward one
     * step per scheduler tick: it skips straight to the next occurrence that
     * is still ahead. Missed occurrences are not re-fired — the delivery
     * engine would suppress them as stale anyway.
     */
    public function nextAfter(
        RecurrenceRule $rule,
        DateTimeInterface $from,
        DateTimeInterface $floor,
    ): ?CarbonImmutable {
        $floor = CarbonImmutable::instance($floor)->utc();
        $next = $this->next($rule, $from);

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            if ($next === null || $next->greaterThan($floor)) {
                return $next;
            }

            $next = $this->next($rule, $next);
        }

        return $next;
    }

    /**
     * Weekly rules. Without chosen weekdays this is a plain N-week hop, which
     * keeps whatever weekday the series already falls on. With them, the next
     * chosen day inside the current week wins; otherwise the series jumps to
     * the first chosen day of the week N weeks on.
     */
    private function nextWeekly(CarbonImmutable $local, RecurrenceRule $rule): CarbonImmutable
    {
        if (! $rule->hasWeekdays()) {
            return $local->addWeeks($rule->interval);
        }

        $weekdays = $rule->sortedWeekdays();
        // Explicit Monday: Carbon's default week start follows the locale.
        $weekStart = $local->startOfWeek(CarbonInterface::MONDAY);

        foreach ($weekdays as $weekday) {
            $candidate = $weekStart->addDays($weekday - 1)->setTimeFrom($local);

            if ($candidate->greaterThan($local)) {
                return $candidate;
            }
        }

        return $weekStart->addWeeks($rule->interval)
            ->addDays($weekdays[0] - 1)
            ->setTimeFrom($local);
    }

    /**
     * Monthly and yearly rules, counted in whole months from the first of the
     * month so month arithmetic can never overflow into the next one.
     *
     * Two ways a rule can pick its day within that month:
     *
     * - Day-of-month (the default): the day comes from the rule's anchor
     *   rather than from the occurrence, because "monthly on the 31st" is
     *   stored as the 28th while it sits in February, and stepping on from
     *   *that* would strand the series on the 28th for good. Clamping is
     *   applied fresh each month against the anchor.
     * - Nth weekday: "the 3rd Wednesday" — see {@see nthWeekdayOfMonth()}.
     */
    private function nextMonthly(CarbonImmutable $local, RecurrenceRule $rule, int $months): CarbonImmutable
    {
        $month = $local->startOfMonth()->addMonths($months);

        if ($rule->isNthWeekday()) {
            return $this->nthWeekdayOfMonth($month, $rule->weekdays[0], $rule->weekOfMonth)
                ->setTimeFrom($local);
        }

        $anchorDay = $rule->anchorDay ?? $local->day;

        return $month->setDay(min($anchorDay, $month->daysInMonth))->setTimeFrom($local);
    }

    /**
     * The date of the Nth occurrence of a weekday within a month — "the 3rd
     * Wednesday of March", or, with `$ordinal` -1, "the last Friday".
     *
     * Every month has at least four of any given weekday, so ordinals 1-4
     * never need the clamping the day-of-month path does; "last" is found by
     * counting back from the month's final day instead of forward from its
     * first, so it lands on whichever week that turns out to be.
     */
    private function nthWeekdayOfMonth(CarbonImmutable $monthStart, int $isoWeekday, int $ordinal): CarbonImmutable
    {
        if ($ordinal === -1) {
            $end = $monthStart->endOfMonth();

            return $end->subDays(($end->dayOfWeekIso - $isoWeekday + 7) % 7);
        }

        $first = $monthStart->startOfMonth();
        $firstOccurrence = $first->addDays(($isoWeekday - $first->dayOfWeekIso + 7) % 7);

        return $firstOccurrence->addWeeks($ordinal - 1);
    }

    /**
     * Whether a candidate falls past the rule's end date. The end date is a
     * local calendar day and it is inclusive, so the comparison is made on
     * local `Y-m-d` strings — never on instants, which would drag the
     * boundary hours either side of midnight.
     */
    private function hasEnded(CarbonImmutable $candidate, RecurrenceRule $rule): bool
    {
        return $rule->until !== null && $candidate->format('Y-m-d') > $rule->until;
    }
}
