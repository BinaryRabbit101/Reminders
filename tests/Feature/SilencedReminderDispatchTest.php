<?php

namespace Tests\Feature;

use App\Models\HeldPush;
use App\Models\Household;
use App\Models\Reminder;
use App\Models\ReminderAlert;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Notifications\ReminderPreAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/**
 * The `is_silenced` toggle where it meets the delivery engine.
 *
 * The rule under test throughout, and the mirror of the one
 * {@see QuietHoursDeliveryTest} states: silence changes **which channels
 * carry an occurrence** — never whether it was claimed, whether the in-app
 * record was written, whether it shows on the board, or whether a recurring
 * series advanced. Every assertion here about `reminder_dispatches` or
 * `due_at` is guarding that boundary rather than the feature itself.
 *
 * Time is frozen throughout, like the rest of the engine suite.
 */
class SilencedReminderDispatchTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE = 'America/Chicago';

    /** The instant most tests here run at, in UTC. */
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

    /** Freeze the clock at a local wall-clock moment. */
    private function freeze(string $local, string $zone = self::ZONE): Carbon
    {
        $now = Carbon::parse($local, $zone)->utc();

        Carbon::setTestNow($now);

        return $now;
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

    public function test_a_silenced_reminder_sends_the_in_app_record_and_no_push()
    {
        Notification::fake();

        $user = User::factory()->create();
        Reminder::factory()->for($user)->silenced()->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo(
            $user,
            fn (ReminderDueNotification $n): bool => $n->via($user)
                === ReminderDueNotification::CHANNELS_IN_APP,
        );

        // The web-push half never went out under any guise — not now, and not
        // parked for later.
        Notification::assertNotSentTo(
            $user,
            fn (ReminderDueNotification $n): bool => in_array(WebPushChannel::class, $n->via($user), true),
        );
        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_a_silenced_occurrence_is_still_claimed_and_marked_sent()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->silenced()->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // Silence is not suppression: the occurrence happened, it is logged
        // as delivered, and a second sweep must not re-run it.
        $this->assertDatabaseHas('reminder_dispatches', [
            'reminder_id' => $reminder->id,
            'due_at' => Carbon::now()->subMinute()->format('Y-m-d H:i:s'),
            'sent_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        $this->assertDatabaseCount('reminder_dispatches', 1);
    }

    public function test_a_silenced_reminder_still_shows_up_as_overdue()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->silenced()->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // The board is the surface silence does *not* touch — the bargain is
        // "tell me quietly", not "hide it".
        $this->assertTrue(Reminder::query()->due()->whereKey($reminder->id)->exists());
    }

    public function test_an_unsilenced_reminder_is_unaffected()
    {
        Notification::fake();

        $user = User::factory()->create();
        Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo(
            $user,
            fn (ReminderDueNotification $n): bool => $n->via($user)
                === ReminderDueNotification::CHANNELS_ALL,
        );
    }

    public function test_silence_belongs_to_the_reminder_so_a_shared_one_is_silent_for_the_household()
    {
        Notification::fake();

        $household = Household::factory()->create();
        $alice = User::factory()->for($household)->create();
        $bob = User::factory()->for($household)->create();

        Reminder::factory()->for($alice)->shared()->silenced()->create([
            'due_at' => Carbon::now(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // Unlike quiet hours, which are the *recipient's*, silence is the
        // reminder's — so it cannot be loud for one member and quiet for the
        // other.
        foreach ([$alice, $bob] as $member) {
            Notification::assertSentTo(
                $member,
                fn (ReminderDueNotification $n): bool => $n->via($member)
                    === ReminderDueNotification::CHANNELS_IN_APP,
            );
        }

        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_a_silenced_reminders_pre_alert_is_silent_too()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->silenced()->create([
            'due_at' => Carbon::now()->addHour(),
        ]);
        ReminderAlert::factory()->for($reminder)->offset(60)->create();

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo(
            $user,
            fn (ReminderPreAlertNotification $n): bool => $n->via($user)
                === ReminderPreAlertNotification::CHANNELS_IN_APP,
        );

        // The alert moment is still claimed — silence is a channel decision,
        // not a reason to leave the occurrence unhandled and fire it again on
        // the next sweep.
        $this->assertDatabaseCount('reminder_alert_dispatches', 1);
        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_quiet_hours_hold_nothing_for_a_silenced_reminder()
    {
        Notification::fake();
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        Reminder::factory()->for($user)->silenced()->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // A held row is a promise to buzz later, and a silenced reminder
        // makes no such promise. Nothing to release at 07:00.
        $this->assertDatabaseCount('held_pushes', 0);

        Notification::fake();
        $this->freeze('2026-08-04 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_silencing_a_reminder_overnight_drops_the_push_already_held()
    {
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->assertDatabaseCount('held_pushes', 1);

        // Ticked at midnight, while the push sits in the queue: withdrawing
        // the promise has to reach the row that was already written.
        $reminder->forceFill(['is_silenced' => true])->save();

        Notification::fake();
        $this->freeze('2026-08-04 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
        // The row is consumed either way — dropped is not the same as left
        // behind to be reconsidered every minute forever.
        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_silencing_a_reminder_overnight_drops_a_held_pre_alert_too()
    {
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->addHour(),
        ]);
        ReminderAlert::factory()->for($reminder)->offset(60)->create();

        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->assertSame(1, HeldPush::query()->whereNotNull('reminder_alert_id')->count());

        $reminder->forceFill(['is_silenced' => true])->save();

        Notification::fake();
        $this->freeze('2026-08-04 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_silence_composes_with_auto_complete()
    {
        Notification::fake();
        config(['reminders.timezone' => self::ZONE]);

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->autoCompleting()
            ->silenced()
            ->create(['due_at' => Carbon::now()->subMinute()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // Two independent decisions: what carries the occurrence, and what
        // happens to the series afterwards. Silence touches only the first.
        $this->assertSame(
            Carbon::parse(self::NOW, 'UTC')->subMinute()->addDay()->format('Y-m-d H:i:s'),
            $reminder->refresh()->due_at->utc()->format('Y-m-d H:i:s'),
        );

        Notification::assertSentTo(
            $user,
            fn (ReminderDueNotification $n): bool => $n->via($user)
                === ReminderDueNotification::CHANNELS_IN_APP,
        );
    }

    public function test_a_stale_silenced_occurrence_is_suppressed_like_any_other()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->silenced()->create([
            'due_at' => Carbon::now()->subHour(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // Silence sits inside the send step, so it never gets a say here: the
        // claim is written, `sent_at` stays null, and not even the in-app
        // record goes out.
        $this->assertDatabaseHas('reminder_dispatches', [
            'reminder_id' => $reminder->id,
            'sent_at' => null,
        ]);
        Notification::assertNothingSent();
    }
}
