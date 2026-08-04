<?php

namespace Tests\Unit;

use App\Support\QuietHours;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The quiet-hours window, in isolation from any database or clock.
 *
 * Everything here is about one thing: the window is a pair of *wall-clock*
 * times, so the answer depends on what the user's own clock reads at a given
 * UTC instant — which is not the same question twice a year.
 */
class QuietHoursTest extends TestCase
{
    private const ZONE = 'America/Chicago';

    private function window(string $start = '22:00', string $end = '07:00', bool $enabled = true, string $zone = self::ZONE): QuietHours
    {
        return new QuietHours($zone, $enabled, $start, $end);
    }

    /** A local wall-clock moment in the test's zone. */
    private function local(string $moment, string $zone = self::ZONE): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, $zone);
    }

    public function test_a_disabled_window_covers_nothing()
    {
        $window = $this->window(enabled: false);

        $this->assertFalse($window->covers($this->local('2026-08-03 23:00')));
        $this->assertFalse($window->isEnabled());
    }

    public function test_a_window_spanning_midnight_covers_both_sides_of_it()
    {
        $window = $this->window('22:00', '07:00');

        $this->assertTrue($window->covers($this->local('2026-08-03 23:00')));
        $this->assertTrue($window->covers($this->local('2026-08-04 02:30')));
        $this->assertTrue($window->covers($this->local('2026-08-04 06:59')));
        $this->assertFalse($window->covers($this->local('2026-08-03 21:59')));
        $this->assertFalse($window->covers($this->local('2026-08-04 09:00')));
    }

    public function test_the_window_is_half_open()
    {
        $window = $this->window('22:00', '07:00');

        // The start is quiet, the end is not — which is exactly what lets a
        // push released at 07:00 go out rather than be held again forever.
        $this->assertTrue($window->covers($this->local('2026-08-03 22:00')));
        $this->assertFalse($window->covers($this->local('2026-08-04 07:00')));
    }

    public function test_a_window_inside_one_day_does_not_wrap()
    {
        $window = $this->window('13:00', '15:00');

        $this->assertTrue($window->covers($this->local('2026-08-03 13:30')));
        $this->assertFalse($window->covers($this->local('2026-08-03 12:59')));
        $this->assertFalse($window->covers($this->local('2026-08-03 15:00')));
        $this->assertFalse($window->covers($this->local('2026-08-03 23:00')));
    }

    public function test_a_zero_length_window_covers_nothing()
    {
        $window = $this->window('22:00', '22:00');

        $this->assertFalse($window->covers($this->local('2026-08-03 22:00')));
        $this->assertFalse($window->covers($this->local('2026-08-04 03:00')));
    }

    public function test_it_reads_the_clock_of_its_own_timezone()
    {
        // 21:00 in Chicago is 02:00 the next morning in UTC. The same "22:00
        // to 07:00" window therefore answers differently depending on whose
        // clock it is read on — an hour before bed in Chicago, the dead of
        // night in UTC. Same instant, same numbers, opposite answers.
        $chicago = $this->window('22:00', '07:00');
        $utc = $this->window('22:00', '07:00', zone: 'UTC');

        $moment = $this->local('2026-08-03 21:00');

        $this->assertFalse($chicago->covers($moment));
        $this->assertTrue($utc->covers($moment));
    }

    public function test_the_window_ends_at_the_next_local_occurrence_of_its_end_time()
    {
        $window = $this->window('22:00', '07:00');

        $release = $window->endsAfter($this->local('2026-08-03 23:00'));

        $this->assertSame(
            '2026-08-04 07:00:00',
            $release->setTimezone(self::ZONE)->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_window_entered_after_midnight_ends_the_same_morning()
    {
        $window = $this->window('22:00', '07:00');

        $release = $window->endsAfter($this->local('2026-08-04 02:00'));

        $this->assertSame(
            '2026-08-04 07:00:00',
            $release->setTimezone(self::ZONE)->format('Y-m-d H:i:s'),
        );
    }

    public function test_the_release_instant_survives_spring_forward()
    {
        $window = $this->window('22:00', '07:00');

        // Held at 23:00 CST (UTC-6) the night the clocks go forward. The
        // window ends at 07:00 CDT (UTC-5), so the UTC instant is 12:00 —
        // an hour *earlier* than a naive "23:00 + 8 hours" would give.
        $release = $window->endsAfter($this->local('2026-03-07 23:00'));

        $this->assertSame('2026-03-08 12:00:00', $release->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2026-03-08 07:00:00',
            $release->setTimezone(self::ZONE)->format('Y-m-d H:i:s'),
        );
    }

    public function test_the_release_instant_survives_fall_back()
    {
        $window = $this->window('22:00', '07:00');

        // Held at 23:00 CDT (UTC-5); the window ends at 07:00 CST (UTC-6),
        // so the UTC instant is 13:00 — an hour later than the spring case.
        $release = $window->endsAfter($this->local('2026-10-31 23:00'));

        $this->assertSame('2026-11-01 13:00:00', $release->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2026-11-01 07:00:00',
            $release->setTimezone(self::ZONE)->format('Y-m-d H:i:s'),
        );
    }

    public function test_coverage_is_decided_on_the_local_clock_across_a_dst_boundary()
    {
        $window = $this->window('22:00', '07:00');

        // 2026-03-08 08:00 UTC is 02:00 CST — which does not exist locally
        // that morning; the clock has already jumped to 03:00 CDT. Either
        // reading is inside the window, which is the point: coverage follows
        // the wall clock, not an offset arithmetic on UTC.
        $this->assertTrue($window->covers(CarbonImmutable::parse('2026-03-08 08:00', 'UTC')));

        // 13:00 UTC that same morning is 08:00 CDT — past the window.
        $this->assertFalse($window->covers(CarbonImmutable::parse('2026-03-08 13:00', 'UTC')));

        // And on fall-back morning, 12:30 UTC is 06:30 CST — still quiet,
        // where the day before it would have been 07:30 CDT and loud.
        $this->assertTrue($window->covers(CarbonImmutable::parse('2026-11-01 12:30', 'UTC')));
        $this->assertFalse($window->covers(CarbonImmutable::parse('2026-10-31 12:30', 'UTC')));
    }

    public function test_a_malformed_window_covers_nothing_rather_than_throwing()
    {
        // This runs inside the per-minute sweep; a bad preference must not be
        // able to stop everybody else's delivery.
        $window = $this->window('not a time', 'also not a time');

        $this->assertFalse($window->covers($this->local('2026-08-03 23:00')));
    }
}
