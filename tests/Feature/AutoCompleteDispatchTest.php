<?php

namespace Tests\Feature;

use App\Console\Commands\SendDueReminders;
use App\Models\Reminder;
use App\Models\ReminderAlert;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Notifications\ReminderPreAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The `auto_complete` opt-in where it meets the delivery engine.
 *
 * The 2026-08-07 recurrence amendment made dispatch stop advancing a series;
 * this flag makes that advance available again, per reminder. Everything under
 * test here is the one exception, and its edges: it hangs off the **claim**
 * (so a stale-suppressed occurrence advances too), it is a no-op without the
 * flag, and it writes no completion-log row because nobody did anything.
 *
 * Time is frozen throughout, like {@see SendDueRemindersTest} — and on the
 * Chicago clock, like {@see RecurrenceTest}, because the next occurrence is
 * computed on the owner's local calendar.
 */
class AutoCompleteDispatchTest extends TestCase
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

    public function test_a_sent_occurrence_advances_an_auto_complete_series()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->autoCompleting()
            ->create(['due_at' => Carbon::now()->subMinute()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderDueNotification::class);

        // The occurrence that just fired is logged, and the series has already
        // stepped past it — this is what the toggle buys.
        $this->assertDatabaseHas('reminder_dispatches', [
            'reminder_id' => $reminder->id,
            'due_at' => Carbon::now()->subMinute()->format('Y-m-d H:i:s'),
            'sent_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $reminder->refresh();

        $this->assertSame(
            Carbon::parse(self::NOW, 'UTC')->subMinute()->addDay()->format('Y-m-d H:i:s'),
            $reminder->due_at->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertNull($reminder->completed_at);
    }

    public function test_an_auto_completed_occurrence_does_not_stay_in_overdue()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->autoCompleting()
            ->create(['due_at' => Carbon::now()->subMinute()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // `due()` is the same scope the Today board's Overdue bucket reads.
        $this->assertFalse(Reminder::query()->due()->whereKey($reminder->id)->exists());
        $this->assertTrue(Reminder::query()->pending()->whereKey($reminder->id)->exists());
    }

    public function test_a_stale_suppressed_occurrence_advances_an_auto_complete_series_too()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->autoCompleting()
            ->create([
                'due_at' => Carbon::now()->subMinutes(SendDueReminders::STALE_AFTER_MINUTES + 1),
            ]);
        $occurrence = $reminder->due_at->utc()->copy();

        $this->artisan('reminders:send-due')->assertSuccessful();

        // Burnt rather than buzzed, exactly as usual...
        Notification::assertNothingSent();
        $this->assertDatabaseHas('reminder_dispatches', [
            'reminder_id' => $reminder->id,
            'sent_at' => null,
        ]);

        // ...and still advanced: the advance hangs off the claim, because
        // "parked in Overdue after downtime" is what this toggle opts out of.
        $this->assertSame(
            $occurrence->addDay()->format('Y-m-d H:i:s'),
            $reminder->refresh()->due_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_second_tick_is_a_no_op()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->autoCompleting()
            ->create(['due_at' => Carbon::now()->subMinute()]);

        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentToTimes($user, ReminderDueNotification::class, 1);
        $this->assertDatabaseCount('reminder_dispatches', 1);

        // One tick, one step: the advanced occurrence is a day ahead of now,
        // so the second sweep never even sees it.
        $this->assertSame(
            Carbon::parse(self::NOW, 'UTC')->subMinute()->addDay()->format('Y-m-d H:i:s'),
            $reminder->refresh()->due_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_without_the_toggle_the_series_still_stays_where_it_was()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->create(['due_at' => Carbon::now()->subMinute()]);
        $due = $reminder->due_at->utc()->format('Y-m-d H:i:s');

        $this->artisan('reminders:send-due')->assertSuccessful();

        // The default is the 2026-08-07 amendment, unchanged: being pushed is
        // not being done.
        $this->assertSame($due, $reminder->refresh()->due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue(Reminder::query()->due()->whereKey($reminder->id)->exists());
    }

    public function test_a_one_off_reminder_is_never_advanced_even_with_the_flag_set()
    {
        Notification::fake();

        $user = User::factory()->create();
        // The form refuses to store this combination; the engine refuses to
        // act on it either, so a row that acquired it some other way cannot
        // silently tick itself off.
        $reminder = Reminder::factory()->for($user)->autoCompleting()->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);
        $due = $reminder->due_at->utc()->format('Y-m-d H:i:s');

        $this->artisan('reminders:send-due')->assertSuccessful();

        $reminder->refresh();

        $this->assertSame($due, $reminder->due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($reminder->completed_at);
    }

    public function test_it_writes_no_completion_log_row()
    {
        Notification::fake();

        $user = User::factory()->create();
        Reminder::factory()->for($user)
            ->repeating('day')
            ->autoCompleting()
            ->create(['due_at' => Carbon::now()->subMinute()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // That log records what a person did, and nobody did anything here —
        // the dispatch row is the record that the occurrence happened.
        $this->assertDatabaseCount('reminder_completions', 0);
        $this->assertDatabaseCount('reminder_dispatches', 1);
    }

    public function test_a_snoozed_occurrence_fires_at_the_snooze_and_advances_from_the_series_anchor()
    {
        Notification::fake();

        $user = User::factory()->create();
        // A 09:00-local daily reminder, snoozed into this afternoon.
        $reminder = Reminder::factory()->for($user)
            ->dueLocal('2026-08-03 09:00')
            ->repeating('day')
            ->autoCompleting()
            ->create(['snoozed_until' => Carbon::now()->subMinute()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderDueNotification::class);

        // The snooze is honoured — the occurrence fired at 12:59, not 09:00 —
        // and only then did the series move.
        $this->assertDatabaseHas('reminder_dispatches', [
            'reminder_id' => $reminder->id,
            'due_at' => Carbon::parse(self::NOW, 'UTC')->subMinute()->format('Y-m-d H:i:s'),
        ]);

        $reminder->refresh();

        // Tomorrow at 09:00, not at 12:59: a snooze moves one occurrence,
        // never the schedule. And the snooze itself is spent.
        $this->assertSame(
            '2026-08-04 09:00',
            $reminder->due_at->setTimezone(self::TIMEZONE)->format('Y-m-d H:i'),
        );
        $this->assertNull($reminder->snoozed_until);
    }

    public function test_a_series_past_its_end_date_is_completed_rather_than_advanced()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->dueLocal('2026-08-03 12:59')
            ->repeating('day', until: '2026-08-03')
            ->autoCompleting()
            ->create();

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderDueNotification::class);

        $reminder->refresh();

        // Correct, and the same call doing it: the series is over, so the last
        // occurrence keeps its due_at and gains a completed_at.
        $this->assertNotNull($reminder->completed_at);
        $this->assertSame(
            '2026-08-03 12:59',
            $reminder->due_at->setTimezone(self::TIMEZONE)->format('Y-m-d H:i'),
        );
        $this->assertSame(0, Reminder::query()->pending()->count());
    }

    public function test_the_next_occurrences_pre_alert_fires_at_the_new_due_moment_minus_its_offset()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->dueLocal('2026-08-03 13:00')
            ->repeating('day')
            ->autoCompleting()
            ->create();
        ReminderAlert::factory()->for($reminder)->offset(60)->create();

        $this->artisan('reminders:send-due')->assertSuccessful();

        // The heads-up for the occurrence that just fired is long past, and
        // the pass skips it without claiming — the reminder is already a day
        // ahead by the time the alert pass runs.
        Notification::assertSentTo($user, ReminderDueNotification::class);
        Notification::assertNotSentTo($user, ReminderPreAlertNotification::class);
        $this->assertDatabaseCount('reminder_alert_dispatches', 0);

        // An hour before tomorrow's 13:00 occurrence.
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00', self::TIMEZONE));

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderPreAlertNotification::class);
        $this->assertDatabaseCount('reminder_alert_dispatches', 1);
    }
}
