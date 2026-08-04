<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\User;
use App\Support\TodayBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Today view's bucketing rules, exercised without going through HTTP.
 *
 * Every case here is really the same question asked in different ways: is the
 * day boundary the *local* midnight or the UTC one? The reminders are created
 * at local wall-times whose UTC date deliberately disagrees with their local
 * date, so bucketing on the stored UTC date would fail every assertion.
 */
class TodayBoardTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();

        config(['reminders.timezone' => self::TIMEZONE]);
    }

    public function test_a_reminder_due_late_yesterday_local_is_overdue_not_today()
    {
        $user = User::factory()->create();

        $reminder = Reminder::factory()->for($user)->dueLocal('2026-08-02 23:30')->create([
            'title' => 'Late last night',
        ]);

        // The trap: stored UTC it lands on 2026-08-03, the same UTC date as
        // the local "today" the board is being built for.
        $this->assertSame('2026-08-03 04:30:00', $reminder->due_at->utc()->format('Y-m-d H:i:s'));

        $board = TodayBoard::make()->for($user, $this->localTime('2026-08-03 08:00'));

        $this->assertSame(['Late last night'], $this->titles($board['overdue']));
        $this->assertSame([], $this->titles($board['today']));
    }

    public function test_a_reminder_due_late_tonight_local_is_today_not_upcoming()
    {
        $user = User::factory()->create();

        $reminder = Reminder::factory()->for($user)->dueLocal('2026-08-03 23:30')->create([
            'title' => 'Before bed',
        ]);

        // The mirror trap: stored UTC it is already tomorrow.
        $this->assertSame('2026-08-04 04:30:00', $reminder->due_at->utc()->format('Y-m-d H:i:s'));

        $board = TodayBoard::make()->for($user, $this->localTime('2026-08-03 08:00'));

        $this->assertSame(['Before bed'], $this->titles($board['today']));
        $this->assertSame([], $board['upcoming']);
    }

    public function test_the_board_splits_reminders_into_three_buckets()
    {
        $user = User::factory()->create();

        $this->reminderFor($user, 'Yesterday', '2026-08-02 09:00');
        $this->reminderFor($user, 'This morning', '2026-08-03 07:00');
        $this->reminderFor($user, 'This evening', '2026-08-03 19:00');
        $this->reminderFor($user, 'Tomorrow', '2026-08-04 09:00');
        $this->reminderFor($user, 'Last day of the window', '2026-08-10 23:00');
        $this->reminderFor($user, 'Beyond the window', '2026-08-11 09:00');

        $board = TodayBoard::make()->for($user, $this->localTime('2026-08-03 08:00'));

        $this->assertSame(['Yesterday', 'This morning'], $this->titles($board['overdue']));
        $this->assertSame(['This evening'], $this->titles($board['today']));
        $this->assertSame(
            ['Tomorrow', 'Last day of the window'],
            $this->titles(array_merge(...array_column($board['upcoming'], 'reminders'))),
        );
        $this->assertSame(7, $board['upcoming_days']);
    }

    public function test_upcoming_is_grouped_by_local_day_with_readable_headings()
    {
        $user = User::factory()->create();

        $this->reminderFor($user, 'Tomorrow morning', '2026-08-04 09:00');
        // 23:30 local on the 4th is 04:30 UTC on the 5th — it must still be
        // grouped under the 4th.
        $this->reminderFor($user, 'Tomorrow night', '2026-08-04 23:30');
        $this->reminderFor($user, 'Midweek', '2026-08-05 12:00');

        $board = TodayBoard::make()->for($user, $this->localTime('2026-08-03 08:00'));

        $this->assertCount(2, $board['upcoming']);

        $this->assertSame('2026-08-04', $board['upcoming'][0]['key']);
        $this->assertSame('Tomorrow', $board['upcoming'][0]['label']);
        $this->assertSame(
            ['Tomorrow morning', 'Tomorrow night'],
            $this->titles($board['upcoming'][0]['reminders']),
        );

        $this->assertSame('2026-08-05', $board['upcoming'][1]['key']);
        $this->assertSame('Wed, Aug 5', $board['upcoming'][1]['label']);
        $this->assertSame(['Midweek'], $this->titles($board['upcoming'][1]['reminders']));
    }

    public function test_the_local_day_survives_the_spring_forward_transition()
    {
        $user = User::factory()->create();

        // 2026-03-08 is the spring-forward date in America/Chicago: the local
        // day is 23 hours long and changes offset halfway through.
        $this->reminderFor($user, 'Before the jump', '2026-03-08 01:30');
        $this->reminderFor($user, 'After the jump', '2026-03-08 23:30');
        $this->reminderFor($user, 'Next morning', '2026-03-09 00:30');

        $board = TodayBoard::make()->for($user, $this->localTime('2026-03-08 01:00'));

        // 01:30 is still CST (UTC-6), 23:30 is already CDT (UTC-5) — both
        // belong to the same local day.
        $this->assertSame(['Before the jump', 'After the jump'], $this->titles($board['today']));
        $this->assertSame([], $this->titles($board['overdue']));

        $this->assertSame('Tomorrow', $board['upcoming'][0]['label']);
        $this->assertSame(['Next morning'], $this->titles($board['upcoming'][0]['reminders']));
        $this->assertSame('Sunday, March 8', $board['today_label']);
    }

    public function test_the_local_day_survives_the_fall_back_transition()
    {
        $user = User::factory()->create();

        // 2026-11-01 is the fall-back date: a 25-hour local day.
        $this->reminderFor($user, 'Late in a long day', '2026-11-01 23:30');
        $this->reminderFor($user, 'Next morning', '2026-11-02 08:00');

        $board = TodayBoard::make()->for($user, $this->localTime('2026-11-01 00:30'));

        // 23:30 local is 05:30 UTC on 2026-11-02, yet it is still today.
        $this->assertSame(['Late in a long day'], $this->titles($board['today']));
        $this->assertSame('Tomorrow', $board['upcoming'][0]['label']);
        $this->assertSame(['Next morning'], $this->titles($board['upcoming'][0]['reminders']));
    }

    public function test_completed_and_other_users_reminders_are_left_out()
    {
        $user = User::factory()->create();

        $this->reminderFor($user, 'Mine', '2026-08-03 19:00');
        Reminder::factory()->for($user)->dueLocal('2026-08-03 18:00')->completed()->create([
            'title' => 'Already done',
        ]);
        Reminder::factory()->dueLocal('2026-08-03 18:00')->create(['title' => 'Theirs']);

        $board = TodayBoard::make()->for($user, $this->localTime('2026-08-03 08:00'));

        $this->assertSame(['Mine'], $this->titles($board['today']));
        $this->assertSame([], $this->titles($board['overdue']));
    }

    public function test_a_reminder_snoozed_into_the_future_leaves_overdue()
    {
        $user = User::factory()->create();

        Reminder::factory()->for($user)->dueLocal('2026-08-01 09:00')->create([
            'title' => 'Snoozed',
            'snoozed_until' => Carbon::parse('2026-08-04 09:00', self::TIMEZONE)->utc(),
        ]);

        $board = TodayBoard::make()->for($user, $this->localTime('2026-08-03 08:00'));

        $this->assertSame([], $this->titles($board['overdue']));
        $this->assertSame('Tomorrow', $board['upcoming'][0]['label']);
        $this->assertSame(['Snoozed'], $this->titles($board['upcoming'][0]['reminders']));
    }

    public function test_an_empty_board_is_still_well_formed()
    {
        $board = TodayBoard::make()->for(User::factory()->create(), $this->localTime('2026-08-03 08:00'));

        $this->assertSame([], $board['overdue']);
        $this->assertSame([], $board['today']);
        $this->assertSame([], $board['upcoming']);
        $this->assertSame('Monday, August 3', $board['today_label']);
    }

    /**
     * A moment expressed on the app's local wall clock.
     */
    private function localTime(string $wallTime): Carbon
    {
        return Carbon::parse($wallTime, self::TIMEZONE);
    }

    private function reminderFor(User $user, string $title, string $localDueAt): Reminder
    {
        return Reminder::factory()->for($user)->dueLocal($localDueAt)->create(['title' => $title]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $presented
     * @return array<int, mixed>
     */
    private function titles(array $presented): array
    {
        return array_column($presented, 'title');
    }
}
