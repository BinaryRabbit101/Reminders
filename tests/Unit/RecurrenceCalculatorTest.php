<?php

namespace Tests\Unit;

use App\Support\RecurrenceCalculator;
use App\Support\RecurrenceRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The recurrence maths, with no database in sight.
 *
 * Every assertion is written as a *local* wall-time, because that is the
 * promise the calculator makes: "every day at 9:00" is nine o'clock on the
 * Chicago wall clock, whatever the UTC offset happens to be that week. The
 * DST cases assert the stored UTC too — that is where a naive "+24 hours"
 * implementation gives itself away.
 */
class RecurrenceCalculatorTest extends TestCase
{
    private const TIMEZONE = 'America/Chicago';

    private RecurrenceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['reminders.timezone' => self::TIMEZONE]);

        $this->calculator = RecurrenceCalculator::make();
    }

    public function test_a_daily_rule_keeps_its_wall_clock_time_across_spring_forward()
    {
        // 2026-03-08 is the US spring-forward Sunday: the clocks jump from
        // 02:00 CST to 03:00 CDT, so the day is 23 hours long.
        $from = $this->local('2026-03-07 09:00');

        $this->assertSame('2026-03-07 15:00:00', $from->utc()->format('Y-m-d H:i:s'));

        $next = $this->calculator->next(new RecurrenceRule('day'), $from);

        // Nine o'clock local either side — one hour *less* elapsed UTC.
        $this->assertSame('2026-03-08 09:00', $this->localString($next));
        $this->assertSame('2026-03-08 14:00:00', $next?->format('Y-m-d H:i:s'));
    }

    public function test_a_daily_rule_keeps_its_wall_clock_time_across_fall_back()
    {
        // 2026-11-01, the mirror case: a 25-hour day.
        $from = $this->local('2026-10-31 09:00');

        $next = $this->calculator->next(new RecurrenceRule('day'), $from);

        $this->assertSame('2026-11-01 09:00', $this->localString($next));
        $this->assertSame('2026-11-01 15:00:00', $next?->format('Y-m-d H:i:s'));
    }

    public function test_a_daily_rule_can_repeat_every_n_days()
    {
        $next = $this->calculator->next(
            new RecurrenceRule('day', interval: 3),
            $this->local('2026-08-03 07:15'),
        );

        $this->assertSame('2026-08-06 07:15', $this->localString($next));
    }

    public function test_a_weekly_rule_without_chosen_days_keeps_its_weekday()
    {
        $next = $this->calculator->next(
            new RecurrenceRule('week', interval: 2),
            // A Monday.
            $this->local('2026-08-03 09:00'),
        );

        $this->assertSame('2026-08-17 09:00', $this->localString($next));
    }

    public function test_a_weekly_rule_walks_through_its_chosen_days()
    {
        $rule = new RecurrenceRule('week', weekdays: [1, 3, 5]);

        // Monday → Wednesday → Friday, all inside the same week.
        $wednesday = $this->calculator->next($rule, $this->local('2026-08-03 09:00'));
        $this->assertSame('2026-08-05 09:00', $this->localString($wednesday));

        $friday = $this->calculator->next($rule, $this->local('2026-08-05 09:00'));
        $this->assertSame('2026-08-07 09:00', $this->localString($friday));
    }

    public function test_a_weekly_rule_rolls_to_the_first_chosen_day_of_the_next_week()
    {
        // Friday is the last chosen day, so the series jumps the week.
        $next = $this->calculator->next(
            new RecurrenceRule('week', weekdays: [1, 3, 5]),
            $this->local('2026-08-07 09:00'),
        );

        $this->assertSame('2026-08-10 09:00', $this->localString($next));
    }

    public function test_a_fortnightly_rule_skips_a_whole_week_between_runs()
    {
        $rule = new RecurrenceRule('week', interval: 2, weekdays: [1, 3]);

        // Within the week the interval does not apply...
        $this->assertSame(
            '2026-08-05 09:00',
            $this->localString($this->calculator->next($rule, $this->local('2026-08-03 09:00'))),
        );

        // ...but leaving it skips a fortnight from the week's Monday.
        $this->assertSame(
            '2026-08-17 09:00',
            $this->localString($this->calculator->next($rule, $this->local('2026-08-05 09:00'))),
        );
    }

    public function test_the_weekday_order_the_chips_were_tapped_in_does_not_matter()
    {
        $next = $this->calculator->next(
            new RecurrenceRule('week', weekdays: [5, 1, 3]),
            $this->local('2026-08-03 09:00'),
        );

        $this->assertSame('2026-08-05 09:00', $this->localString($next));
    }

    public function test_a_monthly_rule_on_the_31st_clamps_to_the_end_of_a_short_month()
    {
        $next = $this->calculator->next(
            new RecurrenceRule('month', anchorDay: 31),
            $this->local('2026-01-31 09:00'),
        );

        // February 2026 has 28 days.
        $this->assertSame('2026-02-28 09:00', $this->localString($next));
    }

    public function test_a_clamped_monthly_rule_climbs_back_to_its_anchor_day()
    {
        // This is the whole reason repeat_anchor_day exists: advancing from
        // the *clamped* February date would strand the series on the 28th.
        $next = $this->calculator->next(
            new RecurrenceRule('month', anchorDay: 31),
            $this->local('2026-02-28 09:00'),
        );

        $this->assertSame('2026-03-31 09:00', $this->localString($next));
    }

    public function test_a_monthly_rule_without_an_anchor_keeps_the_day_it_is_on()
    {
        $next = $this->calculator->next(
            new RecurrenceRule('month'),
            $this->local('2026-08-15 09:00'),
        );

        $this->assertSame('2026-09-15 09:00', $this->localString($next));
    }

    public function test_a_monthly_rule_can_repeat_every_n_months()
    {
        $next = $this->calculator->next(
            new RecurrenceRule('month', interval: 3, anchorDay: 15),
            $this->local('2026-11-15 09:00'),
        );

        // Across the year boundary, and across fall-back: still 09:00 local.
        $this->assertSame('2027-02-15 09:00', $this->localString($next));
    }

    public function test_a_yearly_rule_clamps_february_29_and_climbs_back_in_the_next_leap_year()
    {
        $rule = new RecurrenceRule('year', anchorDay: 29);

        $next = $this->calculator->next($rule, $this->local('2028-02-29 09:00'));
        $this->assertSame('2029-02-28 09:00', $this->localString($next));

        // Three clamped years later the leap day returns — the anchor never
        // got overwritten by the clamp.
        foreach (['2030-02-28', '2031-02-28', '2032-02-29'] as $expected) {
            $next = $this->calculator->next($rule, $next ?? $this->local('2029-02-28 09:00'));
            $this->assertSame($expected.' 09:00', $this->localString($next));
        }
    }

    public function test_a_yearly_rule_can_repeat_every_n_years()
    {
        $next = $this->calculator->next(
            new RecurrenceRule('year', interval: 2),
            $this->local('2026-08-03 09:00'),
        );

        $this->assertSame('2028-08-03 09:00', $this->localString($next));
    }

    public function test_an_end_date_includes_the_occurrence_that_lands_on_it()
    {
        $next = $this->calculator->next(
            new RecurrenceRule('day', until: '2026-08-04'),
            $this->local('2026-08-03 09:00'),
        );

        $this->assertSame('2026-08-04 09:00', $this->localString($next));
    }

    public function test_an_end_date_ends_the_series_once_it_is_passed()
    {
        $this->assertNull($this->calculator->next(
            new RecurrenceRule('day', until: '2026-08-03'),
            $this->local('2026-08-03 09:00'),
        ));
    }

    public function test_the_end_date_is_read_on_the_local_calendar_not_in_utc()
    {
        // 2026-08-04 23:30 local is 2026-08-05 04:30 UTC. Comparing instants
        // instead of local days would end this series a day early.
        $next = $this->calculator->next(
            new RecurrenceRule('day', until: '2026-08-04'),
            $this->local('2026-08-03 23:30'),
        );

        $this->assertSame('2026-08-04 23:30', $this->localString($next));
        $this->assertSame('2026-08-05 04:30:00', $next?->format('Y-m-d H:i:s'));
    }

    public function test_next_after_skips_occurrences_that_are_already_behind()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', self::TIMEZONE));

        // A daily reminder that has been unattended for a week comes back
        // tomorrow, not seven times over the next seven scheduler ticks.
        $next = $this->calculator->nextAfter(
            new RecurrenceRule('day'),
            $this->local('2026-07-27 09:00'),
            CarbonImmutable::now(),
        );

        $this->assertSame('2026-08-04 09:00', $this->localString($next));

        Carbon::setTestNow();
    }

    public function test_next_after_still_ends_a_series_it_catches_up_past()
    {
        $this->assertNull($this->calculator->nextAfter(
            new RecurrenceRule('day', until: '2026-07-30'),
            $this->local('2026-07-27 09:00'),
            $this->local('2026-08-03 12:00'),
        ));
    }

    /**
     * A local wall-time as the UTC instant it is stored as.
     */
    private function local(string $wallTime): CarbonImmutable
    {
        return CarbonImmutable::parse($wallTime, self::TIMEZONE)->utc();
    }

    /**
     * The instant read back on the local wall clock.
     */
    private function localString(?CarbonImmutable $moment): ?string
    {
        return $moment?->setTimezone(self::TIMEZONE)->format('Y-m-d H:i');
    }
}
