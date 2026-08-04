<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A reminder's repeat rule, normalised — "every 2 weeks on Mon and Wed,
 * until Dec 31".
 *
 * Deliberately free of Eloquent so {@see RecurrenceCalculator} stays a pure
 * function of (occurrence + rule): the model builds one of these from its
 * columns, and the calculator never touches the database.
 */
final class RecurrenceRule
{
    /** The repeat units the app understands. `null` on a reminder means one-off. */
    public const UNITS = ['day', 'week', 'month', 'year'];

    /** The widest interval a rule may use — "every 999 months" is plenty. */
    public const MAX_INTERVAL = 999;

    /** Valid values for {@see $weekOfMonth} — first through fourth, or "last". */
    public const WEEKS_OF_MONTH = [1, 2, 3, 4, -1];

    /**
     * @param  string  $unit  One of {@see self::UNITS}.
     * @param  int  $interval  Every N units; at least 1.
     * @param  list<int>  $weekdays  ISO weekdays (1 = Monday). For a weekly
     *                               rule, empty means "the same weekday the
     *                               series already falls on"; for a monthly
     *                               or yearly 'nth_weekday' rule, exactly one
     *                               weekday — the day the rule falls on.
     * @param  string|null  $until  Inclusive end date as local `Y-m-d`.
     * @param  int|null  $anchorDay  The day-of-month the user asked for, for
     *                               a monthly/yearly rule in 'day_of_month'
     *                               mode. See the migration: a clamped
     *                               `due_at` forgets it.
     * @param  string|null  $monthMode  How a monthly/yearly rule picks its
     *                                  day: 'day_of_month' (or null, the
     *                                  default) uses `$anchorDay`;
     *                                  'nth_weekday' uses `$weekOfMonth`
     *                                  together with `$weekdays[0]`.
     * @param  int|null  $weekOfMonth  Which occurrence of the weekday, for a
     *                                 'nth_weekday' rule — one of
     *                                 {@see self::WEEKS_OF_MONTH}.
     */
    public function __construct(
        public readonly string $unit,
        public readonly int $interval = 1,
        public readonly array $weekdays = [],
        public readonly ?string $until = null,
        public readonly ?int $anchorDay = null,
        public readonly ?string $monthMode = null,
        public readonly ?int $weekOfMonth = null,
    ) {
        if (! in_array($unit, self::UNITS, true)) {
            throw new InvalidArgumentException("Unknown repeat unit [{$unit}].");
        }

        if ($interval < 1) {
            throw new InvalidArgumentException('A repeat interval must be at least 1.');
        }

        foreach ($weekdays as $weekday) {
            if ($weekday < 1 || $weekday > 7) {
                throw new InvalidArgumentException("[{$weekday}] is not an ISO weekday.");
            }
        }

        if ($this->isNthWeekday()) {
            if (! in_array($weekOfMonth, self::WEEKS_OF_MONTH, true)) {
                throw new InvalidArgumentException("[{$weekOfMonth}] is not a valid week of the month.");
            }

            if (count($weekdays) !== 1) {
                throw new InvalidArgumentException('An nth-weekday rule needs exactly one weekday.');
            }
        }
    }

    /**
     * The rule's weekdays, de-duplicated and in week order — the form can
     * post them in whatever order the chips were tapped.
     *
     * @return list<int>
     */
    public function sortedWeekdays(): array
    {
        $weekdays = array_values(array_unique($this->weekdays));

        sort($weekdays);

        return $weekdays;
    }

    /**
     * Whether this rule picks specific days within the week, as opposed to
     * simply repeating on whatever weekday the series started.
     */
    public function hasWeekdays(): bool
    {
        return $this->unit === 'week' && $this->weekdays !== [];
    }

    /**
     * Whether this is a "3rd Wednesday" style monthly/yearly rule, as
     * opposed to the plain day-of-month rule those units default to.
     */
    public function isNthWeekday(): bool
    {
        return in_array($this->unit, ['month', 'year'], true) && $this->monthMode === 'nth_weekday';
    }
}
