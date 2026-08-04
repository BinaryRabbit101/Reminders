<?php

namespace Tests\Feature;

use App\Models\HeldPush;
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
 * Quiet hours as the delivery engine sees them.
 *
 * The rule under test throughout: quiet hours change **when the push goes
 * out, and for whom** — never whether the occurrence was claimed, whether the
 * in-app record was written, or whether a recurring series advanced. If any
 * assertion here about `reminder_dispatches` or `due_at` starts failing, the
 * feature has grown past its remit.
 */
class QuietHoursDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE = 'America/Chicago';

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
    private function sleeper(string $start = '22:00', string $end = '07:00', array $attributes = []): User
    {
        return User::factory()->create([
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => $start,
            'quiet_hours_end' => $end,
            ...$attributes,
        ]);
    }

    public function test_a_push_due_inside_quiet_hours_is_held_and_the_in_app_record_is_not()
    {
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // The in-app half went out at the due moment: history and the unread
        // badge are correct at 23:00, which is what the Today view promises.
        $this->assertDatabaseCount('notifications', 1);

        // The push half is parked until morning.
        $held = HeldPush::query()->sole();
        $this->assertSame($user->id, $held->user_id);
        $this->assertSame($reminder->id, $held->reminder_id);
        $this->assertSame(
            $reminder->due_at->format('Y-m-d H:i:s'),
            $held->occurred_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-08-04 07:00:00',
            $held->release_at->setTimezone(self::ZONE)->format('Y-m-d H:i:s'),
        );
    }

    public function test_holding_a_push_leaves_the_dispatch_log_untouched()
    {
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // Claimed and marked sent exactly as a loud occurrence would be: the
        // occurrence *was* delivered, one of its channels is just late. A null
        // sent_at goes on meaning one thing only — a stale claim.
        $this->assertDatabaseHas('reminder_dispatches', [
            'reminder_id' => $reminder->id,
            'due_at' => $reminder->due_at->format('Y-m-d H:i:s'),
            'sent_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_the_held_push_is_sent_when_the_window_ends()
    {
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::fake();

        // A minute before the window ends: still nothing.
        $this->freeze('2026-08-04 06:59');
        $this->artisan('reminders:send-due')->assertSuccessful();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('held_pushes', 1);

        // On the hour: the push goes out, web push only.
        $this->freeze('2026-08-04 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo(
            $user,
            fn (ReminderDueNotification $notification): bool => $notification->via($user) === [WebPushChannel::class],
        );

        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_a_released_push_writes_no_second_history_entry()
    {
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 1);

        $this->freeze('2026-08-04 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();

        // One occurrence, one entry: the release is a buzz, not a new send.
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_a_released_push_carries_the_occurrence_it_was_held_for()
    {
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);
        $occurredAt = $reminder->due_at->copy();

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::fake();
        $this->freeze('2026-08-04 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo(
            $user,
            fn (ReminderDueNotification $notification): bool => $notification->occurredAt
                ->format('Y-m-d H:i:s') === $occurredAt->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_held_push_is_not_suppressed_as_stale_when_it_is_released()
    {
        // Eight hours is far past STALE_AFTER_MINUTES. Holding is a decision
        // the engine made on purpose, so the stale guard must not undo it —
        // otherwise quiet hours would swallow every push they touched.
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::fake();
        $this->freeze('2026-08-04 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderDueNotification::class);
    }

    public function test_a_reminder_due_outside_the_window_pushes_immediately()
    {
        Notification::fake();
        $this->freeze('2026-08-03 09:00');

        $user = $this->sleeper();
        Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo(
            $user,
            fn (ReminderDueNotification $notification): bool => $notification->via($user)
                === ReminderDueNotification::CHANNELS_ALL,
        );

        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_quiet_hours_off_holds_nothing()
    {
        Notification::fake();
        $this->freeze('2026-08-03 23:00');

        // Same hour, quiet hours simply not switched on — the default.
        $user = User::factory()->create();
        Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderDueNotification::class);
        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_a_window_that_does_not_span_midnight_holds_only_inside_itself()
    {
        Notification::fake();
        $this->freeze('2026-08-03 13:30');

        $user = $this->sleeper('13:00', '15:00');
        Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo(
            $user,
            fn (ReminderDueNotification $notification): bool => $notification->via($user)
                === ReminderDueNotification::CHANNELS_IN_APP,
        );
        $this->assertDatabaseCount('held_pushes', 1);

        $this->assertSame(
            '2026-08-03 15:00:00',
            HeldPush::query()->sole()->release_at->setTimezone(self::ZONE)->format('Y-m-d H:i:s'),
        );
    }

    public function test_each_household_member_is_judged_on_their_own_window()
    {
        $this->freeze('2026-08-03 23:00');

        $household = Household::factory()->create();
        // Alice is asleep; Bob is a night owl with no quiet hours at all.
        $alice = $this->sleeper(attributes: ['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $reminder = Reminder::factory()->for($alice)->shared()->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // Both of them get their in-app record now — the reminder is due for
        // the household, whatever hours its members keep.
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $alice->id)->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $bob->id)->count());

        // Only Alice's phone waits.
        $this->assertDatabaseCount('held_pushes', 1);
        $this->assertDatabaseHas('held_pushes', [
            'user_id' => $alice->id,
            'reminder_id' => $reminder->id,
        ]);

        // And still exactly one claim: send-once is about the occurrence.
        $this->assertDatabaseCount('reminder_dispatches', 1);
    }

    public function test_household_members_in_different_zones_release_at_their_own_morning()
    {
        $this->freeze('2026-08-03 23:00');

        $household = Household::factory()->create();
        $chicago = $this->sleeper(attributes: ['household_id' => $household->id]);
        $newYork = $this->sleeper(attributes: [
            'household_id' => $household->id,
            'timezone' => 'America/New_York',
        ]);

        Reminder::factory()->for($chicago)->shared()->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // 23:00 in Chicago is midnight in New York — both are inside a
        // 22:00–07:00 window, but their mornings are an hour apart in UTC.
        $this->assertSame(
            '2026-08-04 12:00:00',
            HeldPush::query()->where('user_id', $chicago->id)->sole()->release_at->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-08-04 11:00:00',
            HeldPush::query()->where('user_id', $newYork->id)->sole()->release_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_one_members_quiet_hours_do_not_delay_the_other_members_push()
    {
        Notification::fake();
        $this->freeze('2026-08-03 23:00');

        $household = Household::factory()->create();
        $alice = $this->sleeper(attributes: ['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        Reminder::factory()->for($alice)->shared()->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo(
            $bob,
            fn (ReminderDueNotification $notification): bool => $notification->via($bob)
                === ReminderDueNotification::CHANNELS_ALL,
        );
        Notification::assertSentTo(
            $alice,
            fn (ReminderDueNotification $notification): bool => $notification->via($alice)
                === ReminderDueNotification::CHANNELS_IN_APP,
        );
    }

    public function test_a_recurring_reminder_still_advances_while_its_push_is_held()
    {
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now(),
            'repeat_unit' => 'day',
            'repeat_interval' => 1,
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // Advancing hangs off the claim, and quiet hours do not touch claims.
        $this->assertSame(
            '2026-08-04 23:00:00',
            $reminder->refresh()->due_at->setTimezone(self::ZONE)->format('Y-m-d H:i:s'),
        );

        // The held push is still about last night's occurrence, and the fact
        // the series moved on must not make it look superseded.
        $this->assertDatabaseCount('held_pushes', 1);

        Notification::fake();
        $this->freeze('2026-08-04 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderDueNotification::class);
    }

    public function test_a_held_push_is_dropped_when_the_reminder_is_completed_overnight()
    {
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        $reminder->forceFill(['completed_at' => Carbon::now()])->save();

        Notification::fake();
        $this->freeze('2026-08-04 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_a_held_push_is_dropped_when_the_occurrence_is_snoozed_forward()
    {
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // Snoozing mints a fresh occurrence that will push on its own; the
        // held one would be a second buzz for the same thing.
        $reminder->snoozeUntil(Carbon::now()->addHours(10));

        Notification::fake();
        $this->freeze('2026-08-04 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_a_held_push_goes_when_its_reminder_does()
    {
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->assertDatabaseCount('held_pushes', 1);

        $reminder->delete();

        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_a_second_sweep_neither_re_holds_nor_re_releases()
    {
        Notification::fake();
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->artisan('reminders:send-due')->assertSuccessful();

        $this->assertDatabaseCount('held_pushes', 1);
        Notification::assertSentToTimes($user, ReminderDueNotification::class, 1);

        $this->freeze('2026-08-04 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->artisan('reminders:send-due')->assertSuccessful();

        // Once for the in-app record, once for the released push. Not thrice.
        Notification::assertSentToTimes($user, ReminderDueNotification::class, 2);
        $this->assertDatabaseCount('held_pushes', 0);
    }

    public function test_a_stale_occurrence_is_suppressed_rather_than_held()
    {
        Notification::fake();
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper();
        Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->subHour(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // Stale wins: the occurrence is burnt, and there is nothing to hold.
        Notification::assertNothingSent();
        $this->assertDatabaseCount('held_pushes', 0);
        $this->assertDatabaseHas('reminder_dispatches', ['sent_at' => null]);
    }

    public function test_a_window_straddling_spring_forward_releases_at_the_right_instant()
    {
        $this->freeze('2026-03-07 23:00');

        $user = $this->sleeper();
        Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // The night is an hour short: 07:00 CDT is 12:00 UTC, not 13:00.
        $this->assertSame(
            '2026-03-08 12:00:00',
            HeldPush::query()->sole()->release_at->utc()->format('Y-m-d H:i:s'),
        );

        Notification::fake();
        $this->freeze('2026-03-08 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderDueNotification::class);
    }

    public function test_a_window_straddling_fall_back_releases_at_the_right_instant()
    {
        $this->freeze('2026-10-31 23:00');

        $user = $this->sleeper();
        Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // And an hour long: 07:00 CST is 13:00 UTC.
        $this->assertSame(
            '2026-11-01 13:00:00',
            HeldPush::query()->sole()->release_at->utc()->format('Y-m-d H:i:s'),
        );

        Notification::fake();
        $this->freeze('2026-11-01 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo($user, ReminderDueNotification::class);
    }

    public function test_the_acceptance_case_from_the_spec()
    {
        // "A reminder due 23:00 with quiet hours 22:00–07:00 pushes at 07:00
        // and shows in the app at 23:00."
        Notification::fake();
        $this->freeze('2026-08-03 23:00');

        $user = $this->sleeper('22:00', '07:00');
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        // In the app at 23:00 — Overdue on the Today board, in the history.
        $board = $this->actingAs($user)->get(route('today'));
        $board->assertOk();
        Notification::assertSentTo(
            $user,
            fn (ReminderDueNotification $n): bool => $n->via($user) === ReminderDueNotification::CHANNELS_IN_APP,
        );

        // Nothing on the phone until seven.
        $this->freeze('2026-08-04 06:00');
        $this->artisan('reminders:send-due')->assertSuccessful();
        $this->assertDatabaseCount('held_pushes', 1);

        $this->freeze('2026-08-04 07:00');
        $this->artisan('reminders:send-due')->assertSuccessful();

        Notification::assertSentTo(
            $user,
            fn (ReminderDueNotification $n): bool => $n->via($user) === ReminderDueNotification::CHANNELS_PUSH
                && $n->reminder->is($reminder),
        );
    }
}
