<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Reminder;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Support\RecurrenceCalculator;
use App\Support\ReminderPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Recurrence where it meets the rest of the app: the delivery engine
 * advancing a series, the form saving one, and the presenter describing one.
 *
 * The maths itself lives in tests/Unit/RecurrenceCalculatorTest.php — what is
 * under test here is the bookkeeping around it, above all that advancing
 * hangs off the dispatch *claim* and can therefore only ever happen once per
 * occurrence.
 */
class RecurrenceTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/Chicago';

    /** Frozen "now" in UTC — 13:00 on the Chicago wall clock (CDT). */
    private const NOW = '2026-08-03 18:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        config(['reminders.timezone' => self::TIMEZONE]);
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dispatching_a_recurring_reminder_advances_it_to_the_next_occurrence()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->repeating('day')->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderDueNotification::class);

        $reminder->refresh();

        $this->assertSame(
            Carbon::parse(self::NOW, 'UTC')->subMinute()->addDay()->format('Y-m-d H:i:s'),
            $reminder->due_at->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertNull($reminder->completed_at);
    }

    public function test_a_second_run_does_not_advance_the_same_occurrence_again()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->repeating('day')->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->artisan('reminders:send-due')->assertSuccessful();

        // One push, one dispatch row, one day of movement — the claim is
        // what gates the advance, so a second tick has nothing to do.
        Notification::assertSentToTimes($user, ReminderDueNotification::class, 1);
        $this->assertDatabaseCount('reminder_dispatches', 1);

        $this->assertSame(
            Carbon::parse(self::NOW, 'UTC')->subMinute()->addDay()->format('Y-m-d H:i:s'),
            $reminder->refresh()->due_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_one_off_reminder_is_neither_advanced_nor_completed_by_being_pushed()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);
        $due = $reminder->due_at->utc()->format('Y-m-d H:i:s');

        $this->artisan('reminders:send-due')->assertSuccessful();

        $reminder->refresh();

        // Being pushed is not the same as being done.
        $this->assertSame($due, $reminder->due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($reminder->completed_at);
    }

    public function test_a_stale_suppressed_occurrence_still_moves_the_series_on()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->repeating('day')->create([
            'due_at' => Carbon::now()->subHour(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseHas('reminder_dispatches', [
            'reminder_id' => $reminder->id,
            'sent_at' => null,
        ]);

        // The occurrence was spent whether or not anyone heard about it;
        // leaving due_at on it would park the reminder in Overdue for good.
        $this->assertSame(
            Carbon::parse(self::NOW, 'UTC')->subHour()->addDay()->format('Y-m-d H:i:s'),
            $reminder->refresh()->due_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_the_series_continues_from_the_schedule_not_from_the_snooze()
    {
        Notification::fake();

        $user = User::factory()->create();
        // A 09:00-local daily reminder, snoozed into this afternoon.
        $reminder = Reminder::factory()->for($user)
            ->dueLocal('2026-08-03 09:00')
            ->repeating('day')
            ->create(['snoozed_until' => Carbon::now()->subMinute()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // The dispatch keys on the effective occurrence — the snooze...
        $this->assertDatabaseHas('reminder_dispatches', [
            'reminder_id' => $reminder->id,
            'due_at' => Carbon::parse(self::NOW, 'UTC')->subMinute()->format('Y-m-d H:i:s'),
        ]);

        $reminder->refresh();

        // ...while the series steps on from due_at, so tomorrow is 09:00
        // again rather than 12:59. A snooze moves one occurrence, never the
        // schedule. And the snooze itself is spent.
        $this->assertSame(
            '2026-08-04 09:00',
            $reminder->due_at->setTimezone(self::TIMEZONE)->format('Y-m-d H:i'),
        );
        $this->assertNull($reminder->snoozed_until);
    }

    public function test_a_snooze_that_outlived_several_occurrences_catches_up_to_the_future()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->dueLocal('2026-07-27 09:00')
            ->repeating('day')
            ->create(['snoozed_until' => Carbon::now()->subMinute()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // A week of missed 09:00s is not replayed one scheduler tick at a
        // time: the series lands on the next occurrence still ahead of now.
        $this->assertSame(
            '2026-08-04 09:00',
            $reminder->refresh()->due_at->setTimezone(self::TIMEZONE)->format('Y-m-d H:i'),
        );
    }

    public function test_a_weekly_rule_advances_to_its_next_chosen_weekday()
    {
        Notification::fake();

        $user = User::factory()->create();
        // 2026-08-03 is a Monday; the rule also runs Wednesdays and Fridays.
        $reminder = Reminder::factory()->for($user)
            ->dueLocal('2026-08-03 12:59')
            ->repeating('week', weekdays: [1, 3, 5])
            ->create();

        $this->artisan('reminders:send-due')->assertSuccessful();

        $this->assertSame(
            '2026-08-05 12:59',
            $reminder->refresh()->due_at->setTimezone(self::TIMEZONE)->format('Y-m-d H:i'),
        );
    }

    public function test_a_series_past_its_end_date_is_completed_instead_of_advanced()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->dueLocal('2026-08-03 12:59')
            ->repeating('day', until: '2026-08-03')
            ->create();

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderDueNotification::class);

        $reminder->refresh();

        // The last occurrence keeps its due_at; completed_at is what says
        // the series is over.
        $this->assertSame(
            '2026-08-03 12:59',
            $reminder->due_at->setTimezone(self::TIMEZONE)->format('Y-m-d H:i'),
        );
        $this->assertNotNull($reminder->completed_at);
        $this->assertSame(0, Reminder::query()->pending()->count());
    }

    public function test_the_compare_and_swap_stops_a_stale_model_from_advancing_twice()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->repeating('day')->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);

        // Two instances of the same row, as two overlapping runs would hold.
        $stale = Reminder::query()->findOrFail($reminder->id);
        $calculator = RecurrenceCalculator::make();

        $this->assertTrue($reminder->advanceOrComplete($calculator));
        $this->assertTrue($stale->advanceOrComplete($calculator));

        // The second call still reports "handled" — losing the race means
        // the work is already done — but the row only moved once.
        $this->assertSame(
            Carbon::parse(self::NOW, 'UTC')->subMinute()->addDay()->format('Y-m-d H:i:s'),
            $reminder->refresh()->due_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_advance_or_complete_reports_a_one_off_reminder_as_unhandled()
    {
        $reminder = Reminder::factory()->create(['due_at' => Carbon::now()->subMinute()]);

        // The seam the future complete endpoint reads: false means "nothing
        // repeats here, set completed_at yourself".
        $this->assertFalse($reminder->advanceOrComplete(RecurrenceCalculator::make()));
        $this->assertNull($reminder->refresh()->completed_at);
    }

    public function test_a_shared_recurring_reminder_still_fans_out_and_advances_once()
    {
        Notification::fake();

        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $reminder = Reminder::factory()->for($alice)->shared()->repeating('day')->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($alice, ReminderDueNotification::class);
        Notification::assertSentTo($bob, ReminderDueNotification::class);

        // Advancing is row-level: one reminder, one advance, however many
        // people it reached.
        $this->assertDatabaseCount('reminder_dispatches', 1);
        $this->assertSame(
            Carbon::parse(self::NOW, 'UTC')->subMinute()->addDay()->format('Y-m-d H:i:s'),
            $reminder->refresh()->due_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_monthly_series_climbs_back_to_its_anchor_day_through_the_engine()
    {
        Notification::fake();

        $user = User::factory()->create();
        // Created on the 31st, so the anchor is 31 even though February will
        // force the stored due_at down to the 28th.
        $reminder = Reminder::factory()->for($user)
            ->dueLocal('2026-01-31 09:00')
            ->repeating('month')
            ->create();

        $this->assertSame(31, $reminder->repeat_anchor_day);

        Carbon::setTestNow(Carbon::parse('2026-01-31 09:01', self::TIMEZONE));
        $this->artisan('reminders:send-due')->assertSuccessful();

        $this->assertSame(
            '2026-02-28 09:00',
            $reminder->refresh()->due_at->setTimezone(self::TIMEZONE)->format('Y-m-d H:i'),
        );

        Carbon::setTestNow(Carbon::parse('2026-02-28 09:01', self::TIMEZONE));
        $this->artisan('reminders:send-due')->assertSuccessful();

        $this->assertSame(
            '2026-03-31 09:00',
            $reminder->refresh()->due_at->setTimezone(self::TIMEZONE)->format('Y-m-d H:i'),
        );
    }

    public function test_a_user_can_create_a_weekly_reminder()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Bins out',
            'due_date' => '2026-08-10',
            'due_time' => '20:00',
            'repeat_unit' => 'week',
            'repeat_interval' => 2,
            // Tapped out of order, on purpose.
            'repeat_weekdays' => [3, 1],
            'repeat_until' => '2026-12-31',
        ])->assertRedirect(route('reminders.index'));

        $reminder = Reminder::query()->sole();

        $this->assertSame('week', $reminder->repeat_unit);
        $this->assertSame(2, $reminder->repeat_interval);
        $this->assertSame([1, 3], $reminder->repeat_weekdays);
        $this->assertSame('2026-12-31', $reminder->repeat_until?->format('Y-m-d'));
        $this->assertNull($reminder->repeat_anchor_day);
    }

    public function test_creating_a_monthly_reminder_records_the_day_the_user_chose()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Rent',
            'due_date' => '2026-08-31',
            'due_time' => '09:00',
            'repeat_unit' => 'month',
        ])->assertRedirect(route('reminders.index'));

        $reminder = Reminder::query()->sole();

        $this->assertSame('month', $reminder->repeat_unit);
        $this->assertSame(1, $reminder->repeat_interval);
        $this->assertSame(31, $reminder->repeat_anchor_day);
        $this->assertNull($reminder->repeat_weekdays);
    }

    public function test_a_user_can_create_a_monthly_nth_weekday_reminder()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Team sync',
            // The third Wednesday of August 2026.
            'due_date' => '2026-08-19',
            'due_time' => '10:00',
            'repeat_unit' => 'month',
            'repeat_month_mode' => 'nth_weekday',
            'repeat_week_of_month' => 3,
            'repeat_weekdays' => [3],
        ])->assertRedirect(route('reminders.index'));

        $reminder = Reminder::query()->sole();

        $this->assertSame('month', $reminder->repeat_unit);
        $this->assertSame('nth_weekday', $reminder->repeat_month_mode);
        $this->assertSame(3, $reminder->repeat_week_of_month);
        $this->assertSame([3], $reminder->repeat_weekdays);
        // Unused in this mode — the day comes from repeat_week_of_month
        // and repeat_weekdays instead.
        $this->assertNull($reminder->repeat_anchor_day);

        $next = RecurrenceCalculator::for($user)->next($reminder->recurrenceRule(), $reminder->due_at);

        // The third Wednesday of the following month.
        $this->assertSame('2026-09-16', $next?->setTimezone(self::TIMEZONE)->format('Y-m-d'));
    }

    public function test_an_nth_weekday_monthly_rule_requires_exactly_one_weekday_and_a_week_of_month()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('reminders.index'))
            ->post(route('reminders.store'), [
                'title' => 'Team sync',
                'due_date' => '2026-08-19',
                'repeat_unit' => 'month',
                'repeat_month_mode' => 'nth_weekday',
                // Missing repeat_week_of_month, and two weekdays instead of
                // the one an nth-weekday rule needs.
                'repeat_weekdays' => [1, 3],
            ])
            ->assertSessionHasErrors(['repeat_week_of_month', 'repeat_weekdays']);

        $this->assertDatabaseCount('reminders', 0);
    }

    public function test_repeat_week_of_month_must_be_a_valid_ordinal()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('reminders.index'))
            ->post(route('reminders.store'), [
                'title' => 'Team sync',
                'due_date' => '2026-08-19',
                'repeat_unit' => 'month',
                'repeat_month_mode' => 'nth_weekday',
                'repeat_week_of_month' => 5,
                'repeat_weekdays' => [3],
            ])
            ->assertSessionHasErrors('repeat_week_of_month');

        $this->assertDatabaseCount('reminders', 0);
    }

    public function test_the_presenter_puts_an_nth_weekday_monthly_rule_into_words()
    {
        $reminder = Reminder::factory()
            ->dueLocal('2026-08-19 09:00')
            ->create([
                'repeat_unit' => 'month',
                'repeat_interval' => 1,
                'repeat_month_mode' => 'nth_weekday',
                'repeat_week_of_month' => 3,
                'repeat_weekdays' => [3],
            ]);

        $presented = ReminderPresenter::make()->present($reminder);

        $this->assertSame('Every month on the third Wednesday', $presented['repeat_label']);
    }

    public function test_the_presenter_puts_an_nth_weekday_yearly_rule_into_words()
    {
        $reminder = Reminder::factory()
            ->dueLocal('2026-11-26 09:00')
            ->create([
                'repeat_unit' => 'year',
                'repeat_interval' => 1,
                'repeat_month_mode' => 'nth_weekday',
                'repeat_week_of_month' => 4,
                'repeat_weekdays' => [4],
            ]);

        $presented = ReminderPresenter::make()->present($reminder);

        $this->assertSame('Every year on the fourth Thursday of Nov', $presented['repeat_label']);
    }

    public function test_an_empty_repeat_selection_saves_a_one_off_reminder()
    {
        $user = User::factory()->create();

        // What the form actually posts when "None" is chosen and the end
        // date input was never touched.
        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Just once',
            'due_date' => '2026-08-10',
            'repeat_unit' => '',
            'repeat_until' => '',
        ])->assertRedirect(route('reminders.index'));

        $reminder = Reminder::query()->sole();

        $this->assertNull($reminder->repeat_unit);
        $this->assertFalse($reminder->isRecurring());
    }

    public function test_turning_a_repeat_off_clears_every_repeat_column()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('week', interval: 3, weekdays: [1, 5], until: '2026-12-31')
            ->create();

        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => 'No longer weekly',
            'due_date' => '2026-09-01',
            'due_time' => '10:00',
        ])->assertRedirect(route('reminders.index'));

        $reminder->refresh();

        $this->assertNull($reminder->repeat_unit);
        $this->assertNull($reminder->repeat_weekdays);
        $this->assertNull($reminder->repeat_until);
        $this->assertSame(1, $reminder->repeat_interval);
    }

    public function test_a_weekly_rule_must_name_at_least_one_weekday()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('reminders.index'))
            ->post(route('reminders.store'), [
                'title' => 'Weekly nothing',
                'due_date' => '2026-08-10',
                'repeat_unit' => 'week',
            ])
            ->assertSessionHasErrors('repeat_weekdays');

        $this->assertDatabaseCount('reminders', 0);
    }

    public function test_the_repeat_rule_is_validated()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('reminders.index'))
            ->post(route('reminders.store'), [
                'title' => 'Nonsense',
                'due_date' => '2026-08-10',
                'repeat_unit' => 'fortnight',
                'repeat_interval' => 1000,
                'repeat_weekdays' => [8],
                // Before the due date, so the series could never run.
                'repeat_until' => '2026-08-01',
            ])
            ->assertSessionHasErrors([
                'repeat_unit',
                'repeat_interval',
                'repeat_weekdays.0',
                'repeat_until',
            ]);

        $this->assertDatabaseCount('reminders', 0);
    }

    public function test_the_index_sends_pre_assembled_repeat_strings_to_the_client()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)
            ->dueLocal('2026-08-03 09:00')
            ->repeating('week', interval: 2, weekdays: [1, 3])
            ->create(['title' => 'Standup']);

        $this->actingAs($user)->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page
                ->where('reminders.0.is_recurring', true)
                ->where('reminders.0.repeat_label', 'Every 2 weeks · Mon, Wed')
                ->where('reminders.0.repeat_unit', 'week')
                ->where('reminders.0.repeat_interval', 2)
                ->where('reminders.0.repeat_weekdays', [1, 3])
                ->etc()
            );
    }

    /**
     * @param  array{0: string, 1: int, 2: list<int>|null, 3: string|null, 4: string}  $case
     */
    #[DataProvider('repeatLabels')]
    public function test_the_presenter_puts_a_repeat_rule_into_words(
        string $unit,
        int $interval,
        ?array $weekdays,
        ?string $until,
        string $expected,
    ) {
        $reminder = Reminder::factory()
            ->dueLocal('2026-08-31 09:00')
            ->repeating($unit, $interval, $weekdays, $until)
            ->create();

        $presented = ReminderPresenter::make()->present($reminder);

        $this->assertSame($expected, $presented['repeat_label']);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: list<int>|null, 3: string|null, 4: string}>
     */
    public static function repeatLabels(): array
    {
        return [
            'daily' => ['day', 1, null, null, 'Every day'],
            'every three days' => ['day', 3, null, null, 'Every 3 days'],
            'weekly' => ['week', 1, null, null, 'Every week'],
            'weekly on days' => ['week', 1, [1, 3], null, 'Every week · Mon, Wed'],
            'fortnightly on days' => ['week', 2, [3, 1], null, 'Every 2 weeks · Mon, Wed'],
            'monthly' => ['month', 1, null, null, 'Every month on the 31st'],
            'quarterly' => ['month', 3, null, null, 'Every 3 months on the 31st'],
            'yearly' => ['year', 1, null, null, 'Every year on Aug 31'],
            'with an end date' => ['day', 1, null, '2026-12-31', 'Every day · until Dec 31, 2026'],
        ];
    }

    public function test_a_one_off_reminder_has_no_repeat_label()
    {
        $reminder = Reminder::factory()->create();

        $presented = ReminderPresenter::make()->present($reminder);

        $this->assertFalse($presented['is_recurring']);
        $this->assertNull($presented['repeat_label']);
        $this->assertSame([], $presented['repeat_weekdays']);
    }
}
