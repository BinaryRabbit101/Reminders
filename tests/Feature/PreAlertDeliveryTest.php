<?php

namespace Tests\Feature;

use App\Console\Commands\SendDueReminders;
use App\Models\HeldPush;
use App\Models\Household;
use App\Models\Reminder;
use App\Models\ReminderAlert;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Notifications\ReminderPreAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/**
 * The delivery engine's second pass: pre-alerts.
 *
 * Time is frozen throughout, like {@see SendDueRemindersTest} — every
 * assertion here is about which side of "now" a computed UTC moment falls on,
 * and a pre-alert's moment is computed rather than stored.
 *
 * The two rules that are this pass's own, and that most of this class is
 * about: an alert is anchored to the **raw** `due_at` (a main snooze never
 * moves it), and it fires only **strictly before** the reminder's effective
 * due moment — skipped without being claimed when that fails, so pushing the
 * due date out later leaves it free to fire after all.
 */
class PreAlertDeliveryTest extends TestCase
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

    /**
     * A reminder due `$minutes` from now, with one pre-alert on it.
     *
     * @return array{0: Reminder, 1: ReminderAlert}
     */
    private function reminderDueIn(User $user, int $minutes, int $offset): array
    {
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->addMinutes($minutes),
        ]);

        $alert = ReminderAlert::factory()->for($reminder)->offset($offset)->create();

        return [$reminder, $alert];
    }

    public function test_a_pre_alert_fires_at_its_offset_before_the_due_moment()
    {
        Notification::fake();

        $user = User::factory()->create();
        [$reminder, $alert] = $this->reminderDueIn($user, 60, 60);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo(
            $user,
            fn (ReminderPreAlertNotification $notification): bool => $notification->alert->is($alert),
        );

        // The reminder itself is an hour off yet — only the heads-up went.
        Notification::assertNotSentTo($user, ReminderDueNotification::class);

        $this->assertDatabaseHas('reminder_alert_dispatches', [
            'reminder_alert_id' => $alert->id,
            'fire_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'sent_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        // The pass never touches the reminder's own dispatch log.
        $this->assertDatabaseCount('reminder_dispatches', 0);
        $this->assertNotNull($reminder->refresh());
    }

    public function test_a_pre_alert_does_not_fire_before_its_moment()
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->reminderDueIn($user, 61, 60);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('reminder_alert_dispatches', 0);
    }

    public function test_a_second_tick_does_not_re_send_the_same_pre_alert()
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->reminderDueIn($user, 60, 60);

        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentToTimes($user, ReminderPreAlertNotification::class, 1);
        $this->assertDatabaseCount('reminder_alert_dispatches', 1);
    }

    public function test_a_pre_alert_is_not_pushed_once_the_main_moment_has_arrived()
    {
        Notification::fake();

        $user = User::factory()->create();
        // Due a minute ago with a day-before alert: its moment passed
        // yesterday, so nothing here is worth buzzing about — the reminder's
        // own notification is what matters now.
        $this->reminderDueIn($user, -1, 1440);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNotSentTo($user, ReminderPreAlertNotification::class);
        $this->assertDatabaseCount('reminder_alert_dispatches', 0);
    }

    public function test_a_short_pre_alert_still_inside_the_stale_window_yields_to_the_due_notification()
    {
        Notification::fake();

        $user = User::factory()->create();
        // The case the `now < effectiveDueAt` gate exists for: a five-minute
        // alert on a reminder that came due a minute ago. Both moments are
        // past, and *both* are still inside the stale window — so nothing
        // else would have stopped this, and comparing the fire moment alone
        // would call 13:54 "before" 13:59 and buzz "Due in 5 minutes." about
        // something that is due right now.
        [$reminder] = $this->reminderDueIn($user, -1, 5);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // The real notification goes out; the heads-up about it does not.
        Notification::assertSentTo(
            $user,
            fn (ReminderDueNotification $notification): bool => $notification->reminder->is($reminder),
        );
        Notification::assertNotSentTo($user, ReminderPreAlertNotification::class);

        // And skipped without claiming — a claim would burn the moment.
        $this->assertDatabaseCount('reminder_alert_dispatches', 0);
        $this->assertDatabaseCount('reminder_dispatches', 1);
    }

    public function test_an_alert_snoozed_past_the_due_moment_is_skipped_without_being_claimed()
    {
        Notification::fake();

        $user = User::factory()->create();
        [$reminder, $alert] = $this->reminderDueIn($user, 30, 60);

        // Accepted input, deliberately: it simply never fires, because the
        // main notification is coming at 14:30 anyway.
        $alert->snoozeUntil(Carbon::now()->subMinute());
        $reminder->forceFill(['due_at' => Carbon::now()->subMinutes(2)])->save();

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNotSentTo($user, ReminderPreAlertNotification::class);

        // Not claimed — that is the point. A claim would burn the moment,
        // and the alert has to stay able to fire if the due date moves out.
        $this->assertDatabaseCount('reminder_alert_dispatches', 0);
    }

    public function test_an_alert_skipped_as_redundant_can_still_fire_when_the_due_date_moves_out()
    {
        Notification::fake();

        $user = User::factory()->create();
        [$reminder, $alert] = $this->reminderDueIn($user, 30, 60);

        $alert->snoozeUntil(Carbon::now()->subMinute());
        $reminder->forceFill(['due_at' => Carbon::now()->subMinutes(2)])->save();

        $this->artisan('reminders:send-due')->assertSuccessful();
        Notification::assertNotSentTo($user, ReminderPreAlertNotification::class);

        // The user pushes the reminder out; the heads-up is meaningful again.
        $reminder->forceFill(['due_at' => Carbon::now()->addHours(2)])->save();

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderPreAlertNotification::class);
        $this->assertDatabaseHas('reminder_alert_dispatches', [
            'reminder_alert_id' => $alert->id,
            'fire_at' => Carbon::now()->subMinute()->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_a_stale_pre_alert_is_claimed_but_never_pushed()
    {
        Notification::fake();

        $user = User::factory()->create();
        // Fires 11 minutes ago, still well before the due moment: past the
        // stale window, so it is burnt rather than buzzed.
        [, $alert] = $this->reminderDueIn($user, 120 - (SendDueReminders::STALE_AFTER_MINUTES + 1), 120);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNotSentTo($user, ReminderPreAlertNotification::class);

        $this->assertDatabaseHas('reminder_alert_dispatches', [
            'reminder_alert_id' => $alert->id,
            'sent_at' => null,
        ]);
    }

    public function test_a_snoozed_alert_fires_again_at_its_snoozed_moment()
    {
        Notification::fake();

        $user = User::factory()->create();
        [, $alert] = $this->reminderDueIn($user, 120, 120);

        $this->artisan('reminders:send-due')->assertSuccessful();
        Notification::assertSentToTimes($user, ReminderPreAlertNotification::class, 1);

        $alert->snoozeUntil(Carbon::now()->addMinutes(10));

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(10));

        $this->artisan('reminders:send-due')->assertSuccessful();

        // The snooze minted a new fire moment and therefore a new claim key
        // — no dispatch row had to be deleted for it to fire again.
        Notification::assertSentToTimes($user, ReminderPreAlertNotification::class, 2);
        $this->assertDatabaseCount('reminder_alert_dispatches', 2);
    }

    public function test_an_alert_stays_anchored_to_the_raw_due_at_when_the_reminder_is_snoozed()
    {
        Notification::fake();

        $user = User::factory()->create();
        [$reminder, $alert] = $this->reminderDueIn($user, 60, 60);

        // Snoozing the *reminder* moves the main occurrence only: "an hour
        // before" goes on meaning an hour before the scheduled time.
        $reminder->snoozeUntil(Carbon::now()->addHours(5));

        $this->assertSame(
            Carbon::now()->format('Y-m-d H:i:s'),
            $alert->refresh()->effectiveFireAt()->format('Y-m-d H:i:s'),
        );

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderPreAlertNotification::class);
    }

    public function test_completing_a_recurring_reminder_clears_alert_snoozes_and_the_next_pre_alert_fires()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->dueLocal('2026-08-03 09:00')
            ->create();
        $alert = ReminderAlert::factory()->for($reminder)->offset(60)->create();

        // This occurrence's heads-up has been pushed out by the user.
        $alert->snoozeUntil(Carbon::now()->addMinutes(30));

        $this->actingAs($user)->post(route('reminders.complete', $reminder));

        // A snooze belongs to the occurrence it was set on, and that
        // occurrence is over — left behind, it would pin the alert to a past
        // moment and tomorrow's heads-up would never fire.
        $this->assertNull($alert->refresh()->snoozed_until);
        $this->assertSame('2026-08-04 14:00:00', $reminder->refresh()->due_at->utc()->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::parse('2026-08-04 13:00:00', 'UTC'));

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderPreAlertNotification::class);
    }

    public function test_a_shared_reminders_pre_alert_fans_out_to_the_household()
    {
        Notification::fake();

        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);
        $carol = User::factory()->create();

        $reminder = Reminder::factory()->for($alice)->shared()->create([
            'due_at' => Carbon::now()->addHour(),
        ]);
        ReminderAlert::factory()->for($reminder)->offset(60)->create();

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($alice, ReminderPreAlertNotification::class);
        Notification::assertSentTo($bob, ReminderPreAlertNotification::class);
        Notification::assertNotSentTo($carol, ReminderPreAlertNotification::class);

        // Send-once is about the moment, not the recipient: one claim.
        $this->assertDatabaseCount('reminder_alert_dispatches', 1);
    }

    public function test_a_pre_alert_writes_an_in_app_row_with_the_pre_alert_kind()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'title' => 'Take the bins out',
            'due_at' => Carbon::now()->addHour(),
        ]);
        $alert = ReminderAlert::factory()->for($reminder)->offset(60)->create();

        // Not faked: this exercises the real database channel.
        $this->artisan('reminders:send-due')->assertSuccessful();

        $row = DB::table('notifications')->first();

        $this->assertNotNull($row);
        $this->assertSame(ReminderPreAlertNotification::class, $row->type);
        $this->assertSame([
            'kind' => 'pre_alert',
            'reminder_id' => $reminder->id,
            'title' => 'Take the bins out',
            'due_at' => $reminder->due_at->toIso8601String(),
            'fire_at' => Carbon::now()->toIso8601String(),
            'offset_minutes' => 60,
        ], json_decode((string) $row->data, true));

        $this->assertSame($alert->id, $alert->refresh()->id);
    }

    public function test_the_push_payload_carries_its_own_tag_and_both_action_urls()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'title' => 'Take the bins out',
            'notes' => 'Green bin this week.',
            'due_at' => Carbon::now()->addHour(),
        ]);
        $alert = ReminderAlert::factory()->for($reminder)->offset(60)->create();

        $notification = new ReminderPreAlertNotification($alert, $alert->effectiveFireAt());

        $this->assertSame([WebPushChannel::class, 'database'], $notification->via($user));

        $push = $notification->toWebPush($user, $notification)->toArray();

        $this->assertSame('Take the bins out', $push['title']);
        // The horizon leads; the notes follow.
        $this->assertSame('Due in 1 hour. Green bin this week.', $push['body']);

        // Never `reminder-{id}`: sharing the due notification's tag would
        // make one bubble replace the other on the phone.
        $this->assertSame("reminder-{$reminder->id}-alert-{$alert->id}", $push['tag']);

        $this->assertSame([
            ['title' => 'Complete', 'action' => 'complete'],
            ['title' => 'Snooze 10m', 'action' => 'snooze'],
        ], $push['actions']);

        $this->assertStringContainsString('signature=', $push['data']['complete_url']);
        $this->assertStringContainsString("reminders/{$reminder->id}/complete", $push['data']['complete_url']);
        $this->assertStringContainsString("alerts/{$alert->id}/snooze", $push['data']['snooze_url']);
        $this->assertStringContainsString('preset=10m', $push['data']['snooze_url']);
        $this->assertSame($alert->id, $push['data']['alert_id']);
    }

    public function test_the_push_body_is_just_the_horizon_when_a_reminder_has_no_notes()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'notes' => null,
            'due_at' => Carbon::now()->addDay(),
        ]);
        $alert = ReminderAlert::factory()->for($reminder)->offset(1440)->create();

        $push = (new ReminderPreAlertNotification($alert, $alert->effectiveFireAt()))
            ->toWebPush($user, new ReminderPreAlertNotification($alert, $alert->effectiveFireAt()))
            ->toArray();

        $this->assertSame('Due in 1 day.', $push['body']);
    }

    public function test_a_pre_alert_inside_quiet_hours_holds_the_push_and_records_it_in_app()
    {
        // 23:00 local, inside a 22:00–07:00 window. The reminder is due at
        // 01:00 local; its two-hour heads-up lands right now.
        Carbon::setTestNow(Carbon::parse('2026-08-03 23:00', 'America/Chicago')->utc());

        $user = $this->sleeper();
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->addHours(2),
        ]);
        $alert = ReminderAlert::factory()->for($reminder)->offset(120)->create();

        $this->artisan('reminders:send-due')->assertSuccessful();

        // The in-app half went out now, exactly like a due notification's.
        $this->assertDatabaseCount('notifications', 1);

        $held = HeldPush::query()->sole();
        $this->assertSame($alert->id, $held->reminder_alert_id);
        $this->assertSame($reminder->id, $held->reminder_id);
        $this->assertTrue($held->isPreAlert());
        $this->assertSame(
            Carbon::now()->format('Y-m-d H:i:s'),
            $held->occurred_at->format('Y-m-d H:i:s'),
        );

        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-04 07:00', 'America/Chicago')->utc());

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo(
            $user,
            fn (ReminderPreAlertNotification $notification): bool => $notification->via($user)
                === ReminderPreAlertNotification::CHANNELS_PUSH,
        );

        $this->assertDatabaseCount('held_pushes', 0);
        // The release is a buzz, not a second send.
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_a_held_pre_alert_is_dropped_when_its_alert_is_snoozed_overnight()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 23:00', 'America/Chicago')->utc());

        $user = $this->sleeper();
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->addHours(2),
        ]);
        $alert = ReminderAlert::factory()->for($reminder)->offset(120)->create();

        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->assertDatabaseCount('held_pushes', 1);

        // A fresh fire moment will push on its own; releasing the old one
        // too would buzz twice about the same thing.
        $alert->snoozeUntil(Carbon::now()->addMinutes(30));

        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-04 07:00', 'America/Chicago')->utc());

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_a_held_pre_alert_is_dropped_when_the_reminder_is_completed_overnight()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 23:00', 'America/Chicago')->utc());

        $user = $this->sleeper();
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->addHours(2),
        ]);
        ReminderAlert::factory()->for($reminder)->offset(120)->create();

        $this->artisan('reminders:send-due')->assertSuccessful();

        $reminder->forceFill(['completed_at' => Carbon::now()])->save();

        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-04 07:00', 'America/Chicago')->utc());

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_a_held_pre_alert_goes_when_its_alert_does()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 23:00', 'America/Chicago')->utc());

        $user = $this->sleeper();
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->addHours(2),
        ]);
        $alert = ReminderAlert::factory()->for($reminder)->offset(120)->create();

        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->assertDatabaseCount('held_pushes', 1);

        // No foreign key backs `reminder_alert_id` (SQLite), so this is the
        // code half of the integrity: a hold whose alert is gone is dropped.
        $alert->delete();

        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-04 07:00', 'America/Chicago')->utc());

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_deleting_a_reminder_takes_its_alerts_and_their_dispatch_log_with_it()
    {
        $user = User::factory()->create();
        [$reminder] = $this->reminderDueIn($user, 60, 60);

        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->assertDatabaseCount('reminder_alert_dispatches', 1);

        $reminder->delete();

        $this->assertDatabaseCount('reminder_alerts', 0);
        $this->assertDatabaseCount('reminder_alert_dispatches', 0);
    }

    public function test_alerts_on_a_completed_reminder_are_never_considered()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->addHour(),
            'completed_at' => Carbon::now()->subDay(),
        ]);
        ReminderAlert::factory()->for($reminder)->offset(60)->create();

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('reminder_alert_dispatches', 0);
    }

    /** A user who holds pushes between the given local hours. */
    private function sleeper(string $start = '22:00', string $end = '07:00'): User
    {
        return User::factory()->create([
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => $start,
            'quiet_hours_end' => $end,
        ]);
    }
}
