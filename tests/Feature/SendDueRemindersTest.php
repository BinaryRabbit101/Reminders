<?php

namespace Tests\Feature;

use App\Console\Commands\SendDueReminders;
use App\Models\Household;
use App\Models\Reminder;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/**
 * The delivery engine. Time is frozen throughout — every assertion here is
 * about which side of "now" a stored UTC moment falls on.
 */
class SendDueRemindersTest extends TestCase
{
    use RefreshDatabase;

    /** The instant every test in this class runs at, in UTC. */
    private const NOW = '2026-08-03 14:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_pushes_a_reminder_that_has_just_come_due()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo(
            $user,
            fn (ReminderDueNotification $notification): bool => $notification->reminder->is($reminder),
        );

        $this->assertDatabaseHas('reminder_dispatches', [
            'reminder_id' => $reminder->id,
            'due_at' => $reminder->due_at->format('Y-m-d H:i:s'),
            'sent_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_it_leaves_reminders_that_are_not_due_yet_alone()
    {
        Notification::fake();

        $user = User::factory()->create();
        Reminder::factory()->for($user)->create(['due_at' => Carbon::now()->addMinute()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('reminder_dispatches', 0);
    }

    public function test_it_skips_completed_reminders()
    {
        Notification::fake();

        $user = User::factory()->create();
        Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->subMinute(),
            'completed_at' => Carbon::now()->subMinutes(2),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('reminder_dispatches', 0);
    }

    public function test_a_reminder_snoozed_into_the_future_is_not_sent()
    {
        Notification::fake();

        $user = User::factory()->create();
        Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->subMinutes(2),
            'snoozed_until' => Carbon::now()->addHour(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('reminder_dispatches', 0);
    }

    public function test_a_snooze_that_has_expired_fires_and_is_keyed_by_the_snoozed_moment()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            // Original moment is long past — only the snooze keeps this
            // fresh enough to send, which is the coalesce doing its job.
            'due_at' => Carbon::now()->subDay(),
            'snoozed_until' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderDueNotification::class);

        // The occurrence recorded is the snooze, not the original due_at —
        // that is what lets the same reminder fire again after a snooze.
        $this->assertDatabaseHas('reminder_dispatches', [
            'reminder_id' => $reminder->id,
            'due_at' => $reminder->snoozed_until?->format('Y-m-d H:i:s'),
        ]);
        $this->assertDatabaseMissing('reminder_dispatches', [
            'reminder_id' => $reminder->id,
            'due_at' => $reminder->due_at->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_a_second_run_does_not_re_send_the_same_occurrence()
    {
        Notification::fake();

        $user = User::factory()->create();
        Reminder::factory()->for($user)->create(['due_at' => Carbon::now()->subMinute()]);

        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentToTimes($user, ReminderDueNotification::class, 1);
        $this->assertDatabaseCount('reminder_dispatches', 1);
    }

    public function test_a_stale_occurrence_is_recorded_but_never_pushed()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->subMinutes(SendDueReminders::STALE_AFTER_MINUTES + 1),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();

        // Burnt on purpose: the row exists so the backlog can never fire,
        // and the null sent_at records that no push went out.
        $this->assertDatabaseHas('reminder_dispatches', [
            'reminder_id' => $reminder->id,
            'sent_at' => null,
        ]);
    }

    public function test_an_occurrence_inside_the_stale_window_still_pushes()
    {
        Notification::fake();

        $user = User::factory()->create();
        Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->subMinutes(SendDueReminders::STALE_AFTER_MINUTES - 1),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderDueNotification::class);
    }

    public function test_each_owner_only_hears_about_their_own_reminders()
    {
        Notification::fake();

        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Reminder::factory()->for($alice)->create(['due_at' => Carbon::now()->subMinute()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($alice, ReminderDueNotification::class);
        Notification::assertNotSentTo($bob, ReminderDueNotification::class);
    }

    public function test_recipients_resolves_to_the_owner_for_a_private_reminder()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        $recipients = $reminder->recipients();

        $this->assertCount(1, $recipients);
        $this->assertTrue($recipients->first()?->is($user));
    }

    public function test_recipients_fans_out_to_the_household_for_a_shared_reminder()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);
        User::factory()->create();

        $reminder = Reminder::factory()->for($alice)->shared()->create();

        $this->assertEqualsCanonicalizing(
            [$alice->id, $bob->id],
            $reminder->recipients()->pluck('id')->all(),
        );
    }

    public function test_a_shared_reminder_pushes_to_both_members_from_one_dispatch_row()
    {
        Notification::fake();

        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);
        $carol = User::factory()->create();

        $reminder = Reminder::factory()->for($alice)->shared()->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($alice, ReminderDueNotification::class);
        Notification::assertSentTo($bob, ReminderDueNotification::class);
        Notification::assertNotSentTo($carol, ReminderDueNotification::class);

        // Send-once is about the occurrence, not the recipient: one row,
        // however many people the notification fanned out to.
        $this->assertDatabaseCount('reminder_dispatches', 1);
        $this->assertDatabaseHas('reminder_dispatches', ['reminder_id' => $reminder->id]);
    }

    public function test_a_private_reminder_never_reaches_the_other_household_member()
    {
        Notification::fake();

        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        Reminder::factory()->for($alice)->create(['due_at' => Carbon::now()->subMinute()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($alice, ReminderDueNotification::class);
        Notification::assertNotSentTo($bob, ReminderDueNotification::class);
    }

    public function test_a_shared_reminder_writes_an_in_app_row_for_each_member()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        Reminder::factory()->for($alice)->shared()->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);

        // Not faked: the database channel is what gives each member their
        // own notification history entry for the same occurrence.
        $this->artisan('reminders:send-due')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $alice->id)->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $bob->id)->count());
    }

    public function test_a_shared_reminder_owned_by_a_household_less_user_reaches_only_them()
    {
        Notification::fake();

        // The flag can outlive membership — leaving does not rewrite rows.
        $loner = User::factory()->create();
        Reminder::factory()->for($loner)->shared()->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentToTimes($loner, ReminderDueNotification::class, 1);
    }

    public function test_the_notification_goes_to_web_push_and_the_database()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'title' => 'Take the bins out',
            'notes' => 'Green bin this week.',
            'due_at' => Carbon::now(),
        ]);

        $notification = new ReminderDueNotification($reminder, $reminder->effectiveDueAt());

        $this->assertSame([WebPushChannel::class, 'database'], $notification->via($user));

        $push = $notification->toWebPush($user, $notification)->toArray();

        $this->assertSame('Take the bins out', $push['title']);
        $this->assertSame('Green bin this week.', $push['body']);
        $this->assertSame(route('today'), $push['data']['url']);

        $this->assertSame([
            'reminder_id' => $reminder->id,
            'title' => 'Take the bins out',
            'due_at' => $reminder->due_at->toIso8601String(),
        ], $notification->toArray($user));
    }

    public function test_the_push_body_falls_back_when_a_reminder_has_no_notes()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['notes' => null]);

        $notification = new ReminderDueNotification($reminder, $reminder->effectiveDueAt());
        $push = $notification->toWebPush($user, $notification)->toArray();

        $this->assertSame('This reminder is due.', $push['body']);
    }

    public function test_it_writes_an_in_app_notification_row()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);

        // Not faked: this exercises the real database channel. The user has
        // no push subscriptions, so WebPushChannel returns without sending.
        $this->artisan('reminders:send-due')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 1);

        $row = DB::table('notifications')->first();
        $this->assertNotNull($row);
        $this->assertSame(ReminderDueNotification::class, $row->type);
        $this->assertSame([
            'reminder_id' => $reminder->id,
            'title' => $reminder->title,
            'due_at' => $reminder->due_at->toIso8601String(),
        ], json_decode((string) $row->data, true));
    }
}
