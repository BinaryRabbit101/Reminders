<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\ReminderList;
use App\Models\User;
use App\Support\ListColor;
use App\Support\ReminderCalendar;
use App\Support\TodayBoard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The month grid behind `?view=calendar`.
 *
 * Two rules are being pinned down here, and both of them are easy to get
 * wrong. The first is the one {@see TodayBoard} exists to
 * enforce: which *cell* a reminder lands in is decided on the local calendar,
 * so the fixtures use wall-times whose UTC date deliberately disagrees with
 * their local one. The second is projection: a repeating reminder stores only
 * the occurrence it is currently on, and every later one the rule produces
 * inside the visible grid has to be drawn as well — without ever turning into
 * a row that could be ticked.
 *
 * Page-render tests call `withoutVite()`: the calendar props ride on an
 * Inertia page whose entry only exists in the Vite manifest after a build.
 */
class ReminderCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();

        config(['reminders.timezone' => self::TIMEZONE]);
    }

    public function test_the_grid_runs_whole_weeks_from_sunday()
    {
        $user = User::factory()->create();

        $calendar = $this->calendar($user, '2026-09', '2026-09-15 08:00');

        $this->assertSame('2026-09', $calendar['month']);
        $this->assertSame('September 2026', $calendar['month_label']);
        $this->assertSame('2026-08', $calendar['prev_month']);
        $this->assertSame('2026-10', $calendar['next_month']);
        $this->assertSame(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], $calendar['weekdays']);

        // September 2026 starts on a Tuesday and ends on a Wednesday, so the
        // grid opens on the Sunday before it and closes on the Saturday after.
        $this->assertCount(5, $calendar['weeks']);
        $this->assertSame('2026-08-30', $calendar['weeks'][0][0]['date']);
        $this->assertSame('2026-10-03', $calendar['weeks'][4][6]['date']);

        $this->assertFalse($this->day($calendar, '2026-08-30')['in_month']);
        $this->assertTrue($this->day($calendar, '2026-09-01')['in_month']);
        $this->assertTrue($this->day($calendar, '2026-09-15')['is_today']);
        $this->assertSame('2026-09-15', $calendar['today']);
        $this->assertSame('Tuesday, September 15', $this->day($calendar, '2026-09-15')['label']);
    }

    public function test_a_reminder_lands_on_its_local_day_not_its_utc_one()
    {
        $user = User::factory()->create();

        $reminder = Reminder::factory()->for($user)->dueLocal('2026-09-30 23:30')->create([
            'title' => 'Late on the last day',
        ]);

        // The trap: stored UTC this is October, and an October grid would be
        // the wrong month to find it in.
        $this->assertSame('2026-10-01 04:30:00', $reminder->due_at->utc()->format('Y-m-d H:i:s'));

        $calendar = $this->calendar($user, '2026-09', '2026-09-15 08:00');

        $this->assertSame(['Late on the last day'], $this->titlesOn($calendar, '2026-09-30'));
        $this->assertSame([], $this->titlesOn($calendar, '2026-10-01'));
        $this->assertSame('11:30 PM', $this->day($calendar, '2026-09-30')['entries'][0]['time_label']);
    }

    public function test_a_repeating_reminder_is_projected_onto_every_occurrence_in_the_grid()
    {
        $user = User::factory()->create();

        Reminder::factory()->for($user)
            ->dueLocal('2026-09-07 09:00')
            ->repeating('week', 1, [1])
            ->create(['title' => 'Bins out']);

        $calendar = $this->calendar($user, '2026-09', '2026-09-01 08:00');

        // Every Monday of the grid, the one stored row included.
        foreach (['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28'] as $monday) {
            $this->assertSame(['Bins out'], $this->titlesOn($calendar, $monday), "Missing from {$monday}.");
            $this->assertSame('9:00 AM', $this->day($calendar, $monday)['entries'][0]['time_label']);
        }

        // Only the occurrence the row actually sits on is a row. The rest are
        // computed, and nothing about them may look actionable.
        $this->assertFalse($this->day($calendar, '2026-09-07')['entries'][0]['is_projected']);
        $this->assertTrue($this->day($calendar, '2026-09-14')['entries'][0]['is_projected']);

        // A Monday in the trailing week belongs to October and is not drawn.
        $this->assertSame([], $this->titlesOn($calendar, '2026-09-01'));
    }

    public function test_a_projection_is_never_completed_snoozed_or_overdue()
    {
        $user = User::factory()->create();

        Reminder::factory()->for($user)
            ->dueLocal('2026-09-07 09:00')
            ->repeating('week', 1, [1])
            ->create([
                'title' => 'Bins out',
                'snoozed_until' => Carbon::parse('2026-09-08 09:00', self::TIMEZONE)->utc(),
            ]);

        // Mid-morning on the day it went off and was pushed to tomorrow: the
        // snooze is still ahead, which is what makes it a snooze at all.
        $calendar = $this->calendar($user, '2026-09', '2026-09-07 12:00');

        // The stored occurrence went where the snooze put it.
        $this->assertSame(['Bins out'], $this->titlesOn($calendar, '2026-09-08'));
        $this->assertTrue($this->day($calendar, '2026-09-08')['entries'][0]['is_snoozed']);
        $this->assertSame([], $this->titlesOn($calendar, '2026-09-07'));

        // The series itself never moved: the projections still run Mondays,
        // and none of them inherits the snooze.
        $projected = $this->day($calendar, '2026-09-21')['entries'][0];

        $this->assertTrue($projected['is_projected']);
        $this->assertFalse($projected['is_snoozed']);
        $this->assertFalse($projected['is_completed']);
        $this->assertFalse($projected['is_overdue']);
    }

    public function test_a_projection_stops_at_the_rules_end_date()
    {
        $user = User::factory()->create();

        Reminder::factory()->for($user)
            ->dueLocal('2026-09-07 09:00')
            ->repeating('week', 1, [1], '2026-09-21')
            ->create(['title' => 'Course homework']);

        $calendar = $this->calendar($user, '2026-09', '2026-09-01 08:00');

        $this->assertSame(['Course homework'], $this->titlesOn($calendar, '2026-09-21'));
        $this->assertSame([], $this->titlesOn($calendar, '2026-09-28'));
    }

    public function test_a_daily_series_is_projected_into_a_month_far_ahead()
    {
        $user = User::factory()->create();

        Reminder::factory()->for($user)
            ->dueLocal('2026-09-15 07:00')
            ->repeating('day')
            ->create(['title' => 'Take the pill']);

        // The point of jumping to the window rather than stepping from the
        // series' current position: a year of daily occurrences is far more
        // steps than the grid's own projection cap.
        $calendar = $this->calendar($user, '2027-06', '2026-09-15 08:00');

        $this->assertSame(['Take the pill'], $this->titlesOn($calendar, '2027-06-01'));
        $this->assertSame(['Take the pill'], $this->titlesOn($calendar, '2027-06-30'));
        $this->assertTrue($this->day($calendar, '2027-06-01')['entries'][0]['is_projected']);
    }

    public function test_an_entry_carries_the_viewers_own_list_colour()
    {
        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create(['color' => 'emerald']);

        Reminder::factory()->for($user)->dueLocal('2026-09-10 09:00')->create([
            'title' => 'Filed',
            'list_id' => $list->id,
        ]);
        Reminder::factory()->for($user)->dueLocal('2026-09-11 09:00')->create(['title' => 'Unfiled']);

        $calendar = $this->calendar($user, '2026-09', '2026-09-01 08:00');

        $this->assertSame(ListColor::Emerald->hex(), $this->day($calendar, '2026-09-10')['entries'][0]['color_hex']);
        $this->assertNull($this->day($calendar, '2026-09-11')['entries'][0]['color_hex']);
    }

    public function test_entries_in_a_day_are_ordered_by_time()
    {
        $user = User::factory()->create();

        Reminder::factory()->for($user)->dueLocal('2026-09-10 17:00')->create(['title' => 'Evening']);
        Reminder::factory()->for($user)->dueLocal('2026-09-10 06:30')->create(['title' => 'Dawn']);
        Reminder::factory()->for($user)->dueLocal('2026-09-10 12:00')->create(['title' => 'Noon']);

        $calendar = $this->calendar($user, '2026-09', '2026-09-01 08:00');

        $this->assertSame(['Dawn', 'Noon', 'Evening'], $this->titlesOn($calendar, '2026-09-10'));
    }

    public function test_an_unparsable_month_falls_back_to_the_month_the_viewer_is_in()
    {
        $user = User::factory()->create();

        foreach ([null, '', 'yesterday', '2026-13', '202609'] as $month) {
            $calendar = $this->calendar($user, $month, '2026-09-15 08:00');

            $this->assertSame('2026-09', $calendar['month'], 'Month: '.var_export($month, true));
        }
    }

    public function test_the_calendar_view_renders_the_month_grid_and_the_list_view_does_not()
    {
        $this->travelTo(Carbon::parse('2026-09-15 08:00', self::TIMEZONE));

        $user = User::factory()->create();
        Reminder::factory()->for($user)->dueLocal('2026-09-16 09:00')->create(['title' => 'Dentist']);

        $this->withoutVite()->actingAs($user)->get(route('reminders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reminders/Index')
                ->where('view', 'list')
                ->where('calendar', null)
                ->etc()
            );

        $this->withoutVite()->actingAs($user)->get(route('reminders.index', ['view' => 'calendar']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reminders/Index')
                ->where('view', 'calendar')
                ->where('calendar.month', '2026-09')
                ->where('calendar.month_label', 'September 2026')
                ->has('calendar.weeks', 5)
                // The rows travel in full as well: the day panel underneath
                // the grid draws the same cards the Today board does.
                ->has('reminders', 1)
                ->etc()
            );
    }

    public function test_the_calendar_shows_the_month_asked_for()
    {
        $this->travelTo(Carbon::parse('2026-09-15 08:00', self::TIMEZONE));

        $user = User::factory()->create();

        $this->withoutVite()->actingAs($user)
            ->get(route('reminders.index', ['view' => 'calendar', 'month' => '2026-12']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('calendar.month_label', 'December 2026')
                // "Today" still knows where to jump back to.
                ->where('calendar.current_month', '2026-09')
                ->etc()
            );
    }

    public function test_the_calendar_is_filtered_exactly_like_the_list()
    {
        $this->travelTo(Carbon::parse('2026-09-15 08:00', self::TIMEZONE));

        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create();

        Reminder::factory()->for($user)->dueLocal('2026-09-16 09:00')->create([
            'title' => 'Filed',
            'list_id' => $list->id,
        ]);
        Reminder::factory()->for($user)->dueLocal('2026-09-17 09:00')->create(['title' => 'Unfiled']);
        Reminder::factory()->for($user)->dueLocal('2026-09-18 09:00')->completed()->create(['title' => 'Done']);

        $filtered = $this->calendarProp($user, ['view' => 'calendar', 'list' => $list->id]);

        $this->assertSame(['Filed'], $this->titlesOn($filtered, '2026-09-16'));
        $this->assertSame([], $this->titlesOn($filtered, '2026-09-17'));

        $unfiltered = $this->calendarProp($user, ['view' => 'calendar']);

        $this->assertSame(['Unfiled'], $this->titlesOn($unfiltered, '2026-09-17'));
        // Completed rows are hidden by default here for the same reason they
        // are in the list — the toggle is what brings them back.
        $this->assertSame([], $this->titlesOn($unfiltered, '2026-09-18'));

        $withCompleted = $this->calendarProp($user, ['view' => 'calendar', 'show_completed' => 1]);

        $this->assertSame(['Done'], $this->titlesOn($withCompleted, '2026-09-18'));
        $this->assertTrue($this->day($withCompleted, '2026-09-18')['entries'][0]['is_completed']);
    }

    /**
     * The grid for a user, built from the rows the index would have fetched.
     *
     * @return array<string, mixed>
     */
    private function calendar(User $user, ?string $month, string $wallTime): array
    {
        /** @var Collection<int, Reminder> $rows */
        $rows = Reminder::query()->visibleTo($user)->with('user')->pending()->orderBy('due_at')->get();

        return ReminderCalendar::make()->for(
            $user,
            $rows,
            $month,
            Carbon::parse($wallTime, self::TIMEZONE),
        );
    }

    /**
     * The same grid, but as the page actually hands it over.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function calendarProp(User $user, array $query): array
    {
        $response = $this->withoutVite()->actingAs($user)->get(route('reminders.index', $query));

        $response->assertOk();

        /** @var array<string, mixed> $calendar */
        $calendar = $response->viewData('page')['props']['calendar'];

        return $calendar;
    }

    /**
     * One cell of the grid, by local date.
     *
     * @param  array<string, mixed>  $calendar
     * @return array<string, mixed>
     */
    private function day(array $calendar, string $date): array
    {
        foreach ($calendar['weeks'] as $week) {
            foreach ($week as $day) {
                if ($day['date'] === $date) {
                    return $day;
                }
            }
        }

        $this->fail("The grid has no cell for {$date}.");
    }

    /**
     * The titles drawn on one day, in the order they are drawn.
     *
     * @param  array<string, mixed>  $calendar
     * @return list<string>
     */
    private function titlesOn(array $calendar, string $date): array
    {
        return array_map(
            fn (array $entry): string => $entry['title'],
            $this->day($calendar, $date)['entries'],
        );
    }
}
