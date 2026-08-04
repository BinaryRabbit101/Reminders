<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Reminder;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Completing and snoozing from inside the app.
 *
 * Time is frozen throughout: every assertion is about where a stored UTC
 * moment lands, and the "tomorrow morning" preset is a local calendar
 * question that only means anything against a fixed now.
 */
class ReminderActionTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-08-03 14:00 UTC is 09:00 in America/Chicago (CDT). */
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

    public function test_guests_cannot_complete_or_snooze()
    {
        $reminder = Reminder::factory()->create();

        $this->post(route('reminders.complete', $reminder))->assertRedirect(route('login'));
        $this->post(route('reminders.snooze', $reminder), ['preset' => '1h'])
            ->assertRedirect(route('login'));
    }

    public function test_completing_a_one_off_reminder_sets_completed_at()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('today'))
            ->post(route('reminders.complete', $reminder))
            ->assertRedirect(route('today'));

        $this->assertSame(
            Carbon::now()->format('Y-m-d H:i:s'),
            $reminder->refresh()->completed_at?->format('Y-m-d H:i:s'),
        );
    }

    public function test_completing_flashes_an_undo_toast_carrying_the_prior_state()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Water the plants']);
        $priorDueAt = $reminder->due_at->toIso8601String();

        $this->actingAs($user)
            ->post(route('reminders.complete', $reminder))
            ->assertSessionHas('inertia.flash_data.toast.message', 'Completed')
            ->assertSessionHas('inertia.flash_data.toast.description', 'Water the plants')
            ->assertSessionHas('inertia.flash_data.toast.undo.url', route('reminders.restore', $reminder))
            // The snapshot is taken before anything moves — that is the
            // whole undo mechanism, and nothing is kept server-side.
            ->assertSessionHas('inertia.flash_data.toast.undo.data', [
                'completed_at' => null,
                'due_at' => $priorDueAt,
                'snoozed_until' => null,
            ]);
    }

    public function test_completing_a_recurring_reminder_advances_it_instead()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->dueLocal('2026-08-03 09:00')
            ->create();

        $this->actingAs($user)->post(route('reminders.complete', $reminder));

        $reminder->refresh();

        $this->assertNull($reminder->completed_at);
        // Tomorrow at 09:00 local, which is 14:00 UTC in August.
        $this->assertSame('2026-08-04 14:00:00', $reminder->due_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_undoing_a_completed_recurring_reminder_rewinds_the_occurrence()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->dueLocal('2026-08-03 09:00')
            ->create();

        $response = $this->actingAs($user)->post(route('reminders.complete', $reminder));

        /** @var array<string, mixed> $flash */
        $flash = $response->getSession()->get('inertia.flash_data');
        /** @var array{url: string, data: array<string, string|null>} $undo */
        $undo = $flash['toast']['undo'];

        $this->actingAs($user)->post($undo['url'], $undo['data']);

        // Back on the occurrence it started from, not a step ahead.
        $this->assertSame(
            '2026-08-03 14:00:00',
            $reminder->refresh()->due_at->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertNull($reminder->completed_at);
    }

    public function test_undo_restores_a_completed_one_off_reminder()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        $this->actingAs($user)->post(route('reminders.complete', $reminder));
        $this->assertNotNull($reminder->refresh()->completed_at);

        $this->actingAs($user)->post(route('reminders.restore', $reminder), [
            'completed_at' => null,
            'due_at' => $reminder->due_at->toIso8601String(),
            'snoozed_until' => null,
        ]);

        $this->assertNull($reminder->refresh()->completed_at);
    }

    public function test_restoring_requires_a_due_moment()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('reminders.index'))
            ->post(route('reminders.restore', $reminder), ['due_at' => 'not-a-date'])
            ->assertSessionHasErrors('due_at');
    }

    /**
     * @return list<array{string, string}>
     */
    public static function snoozePresetProvider(): array
    {
        return [
            // preset, expected snoozed_until in UTC
            ['10m', '2026-08-03 14:10:00'],
            ['1h', '2026-08-03 15:00:00'],
            ['3h', '2026-08-03 17:00:00'],
            // Tomorrow at the configured default time, 09:00 local.
            ['tomorrow', '2026-08-04 14:00:00'],
        ];
    }

    #[DataProvider('snoozePresetProvider')]
    public function test_each_preset_snoozes_to_the_expected_moment(string $preset, string $expected)
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->overdue()->create();

        $this->actingAs($user)
            ->from(route('today'))
            ->post(route('reminders.snooze', $reminder), ['preset' => $preset])
            ->assertRedirect(route('today'));

        $this->assertSame(
            $expected,
            $reminder->refresh()->snoozed_until?->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_tomorrow_morning_stays_at_nine_local_across_a_dst_boundary()
    {
        // The night the clocks go forward in America/Chicago: 15:00 UTC is
        // 09:00 CST today, and 09:00 CDT tomorrow is 14:00 UTC. Plain
        // interval arithmetic would land an hour out.
        Carbon::setTestNow(Carbon::parse('2026-03-07 15:00:00', 'UTC'));

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->overdue()->create();

        $this->actingAs($user)->post(route('reminders.snooze', $reminder), ['preset' => 'tomorrow']);

        $snoozed = $reminder->refresh()->snoozed_until;

        $this->assertSame('2026-03-08 14:00:00', $snoozed?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(
            '09:00',
            $snoozed?->setTimezone((string) config('reminders.timezone'))->format('H:i'),
        );
    }

    public function test_a_custom_local_moment_can_be_snoozed_to()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        $this->actingAs($user)->post(route('reminders.snooze', $reminder), [
            'until_date' => '2026-08-10',
            'until_time' => '18:30',
        ]);

        // 18:30 CDT is 23:30 UTC — converted once, on the way in.
        $this->assertSame(
            '2026-08-10 23:30:00',
            $reminder->refresh()->snoozed_until?->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_snoozing_leaves_the_series_schedule_alone()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->dueLocal('2026-08-03 09:00')
            ->create();

        $this->actingAs($user)->post(route('reminders.snooze', $reminder), ['preset' => '3h']);

        $reminder->refresh();

        // The snooze moves one occurrence; due_at is where the series is.
        $this->assertSame('2026-08-03 14:00:00', $reminder->due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-03 17:00:00', $reminder->snoozed_until?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_snoozing_rejects_an_unknown_preset_and_an_empty_request()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('today'))
            ->post(route('reminders.snooze', $reminder), ['preset' => 'forever'])
            ->assertSessionHasErrors('preset');

        $this->actingAs($user)
            ->from(route('today'))
            ->post(route('reminders.snooze', $reminder), [])
            ->assertSessionHasErrors(['preset', 'until_date']);

        $this->assertNull($reminder->refresh()->snoozed_until);
    }

    public function test_snoozing_into_the_past_is_refused()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        // A past snooze would be instantly due again — and is the only way
        // to collide with a dispatch row the engine has already claimed.
        $this->actingAs($user)
            ->from(route('today'))
            ->post(route('reminders.snooze', $reminder), [
                'until_date' => '2026-07-01',
                'until_time' => '09:00',
            ])
            ->assertSessionHasErrors('until_date');

        $this->assertNull($reminder->refresh()->snoozed_until);
    }

    public function test_a_user_cannot_act_on_another_users_reminder()
    {
        $reminder = Reminder::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(route('reminders.complete', $reminder))->assertForbidden();
        $this->actingAs($intruder)
            ->post(route('reminders.snooze', $reminder), ['preset' => '1h'])
            ->assertForbidden();
        $this->actingAs($intruder)
            ->post(route('reminders.restore', $reminder), ['due_at' => Carbon::now()->toIso8601String()])
            ->assertForbidden();

        $reminder->refresh();
        $this->assertNull($reminder->completed_at);
        $this->assertNull($reminder->snoozed_until);
    }

    public function test_a_household_member_can_complete_a_shared_reminder_for_both()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $reminder = Reminder::factory()->for($alice)->shared()->create();

        // Row-level: one reminder, not two copies, so Bob ticking it off
        // ticks it off for Alice as well.
        $this->actingAs($bob)->post(route('reminders.complete', $reminder))->assertRedirect();

        $this->assertNotNull($reminder->refresh()->completed_at);
    }

    public function test_a_household_member_can_snooze_a_shared_reminder()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $reminder = Reminder::factory()->for($alice)->shared()->overdue()->create();

        $this->actingAs($bob)->post(route('reminders.snooze', $reminder), ['preset' => '1h']);

        $this->assertSame(
            '2026-08-03 15:00:00',
            $reminder->refresh()->snoozed_until?->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_household_member_cannot_touch_a_private_reminder()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $reminder = Reminder::factory()->for($alice)->create();

        $this->actingAs($bob)->post(route('reminders.complete', $reminder))->assertForbidden();
        $this->actingAs($bob)
            ->post(route('reminders.snooze', $reminder), ['preset' => '1h'])
            ->assertForbidden();
    }

    public function test_a_snoozed_reminder_leaves_overdue_and_says_when_it_returns()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->subHours(3),
        ]);

        $this->actingAs($user)->get(route('today'))
            ->assertInertia(fn ($page) => $page->has('board.overdue', 1));

        $this->actingAs($user)->post(route('reminders.snooze', $reminder), ['preset' => '3h']);

        $this->actingAs($user)->get(route('today'))
            ->assertInertia(fn ($page) => $page
                ->has('board.overdue', 0)
                ->has('board.today', 1)
                ->where('board.today.0.is_snoozed', true)
                ->where('board.today.0.snooze_label', 'Snoozed until Mon, Aug 3, 12:00 PM')
            );
    }

    public function test_an_expired_snooze_is_not_badged_as_snoozed()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->subHours(3),
            'snoozed_until' => Carbon::now()->subMinute(),
        ]);

        // It is overdue again, not snoozed — the badge would be a lie.
        $this->actingAs($user)->get(route('today'))
            ->assertInertia(fn ($page) => $page
                ->has('board.overdue', 1)
                ->where('board.overdue.0.is_snoozed', false)
                ->where('board.overdue.0.snooze_label', null)
            );
    }

    public function test_snoozing_a_fired_occurrence_makes_it_fire_again()
    {
        Notification::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'due_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();
        Notification::assertSentToTimes($user, ReminderDueNotification::class, 1);

        $this->actingAs($user)->post(route('reminders.snooze', $reminder), ['preset' => '1h']);

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addHour()->addMinute());

        $this->artisan('reminders:send-due')->assertSuccessful();

        // No dispatch row was deleted: the effective occurrence moved, so
        // the claim key is new and the reminder re-fires by itself.
        Notification::assertSentToTimes($user, ReminderDueNotification::class, 2);
        $this->assertDatabaseCount('reminder_dispatches', 2);
    }
}
