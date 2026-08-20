<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Reminder;
use App\Models\ReminderAlert;
use App\Models\User;
use App\Notifications\ReminderPreAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Snoozing a pre-alert — from inside the app, and from the push notification
 * itself.
 *
 * The invariant every test here is really about: **only**
 * `reminder_alerts.snoozed_until` may move. Pushing the hour-before nudge out
 * ten minutes must never touch the reminder it is a nudge about.
 */
class PreAlertSnoozeTest extends TestCase
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

    /**
     * A reminder due in two hours with an hour-before alert on it.
     *
     * @return array{0: Reminder, 1: ReminderAlert}
     */
    private function alerted(User $user, bool $shared = false): array
    {
        $factory = Reminder::factory()->for($user);

        if ($shared) {
            $factory = $factory->shared();
        }

        $reminder = $factory->create(['due_at' => Carbon::now()->addHours(2)]);

        return [$reminder, ReminderAlert::factory()->for($reminder)->offset(60)->create()];
    }

    public function test_guests_cannot_snooze_an_alert()
    {
        [$reminder, $alert] = $this->alerted(User::factory()->create());

        $this->post(route('reminders.alerts.snooze', [$reminder, $alert]), ['preset' => '10m'])
            ->assertRedirect(route('login'));

        $this->assertNull($alert->refresh()->snoozed_until);
    }

    public function test_the_owner_can_snooze_an_alert_to_a_preset()
    {
        $user = User::factory()->create();
        [$reminder, $alert] = $this->alerted($user);

        $this->actingAs($user)
            ->from(route('today'))
            ->post(route('reminders.alerts.snooze', [$reminder, $alert]), ['preset' => '10m'])
            ->assertRedirect(route('today'));

        $this->assertSame(
            '2026-08-03 14:10:00',
            $alert->refresh()->snoozed_until?->utc()->format('Y-m-d H:i:s'),
        );

        // The reminder itself has not moved an inch.
        $reminder->refresh();
        $this->assertNull($reminder->snoozed_until);
        $this->assertSame('2026-08-03 16:00:00', $reminder->due_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_an_alert_can_be_snoozed_to_a_custom_local_moment()
    {
        $user = User::factory()->create();
        [$reminder, $alert] = $this->alerted($user);

        $this->actingAs($user)->post(route('reminders.alerts.snooze', [$reminder, $alert]), [
            'until_date' => '2026-08-03',
            'until_time' => '10:30',
        ]);

        // 10:30 CDT is 15:30 UTC — converted once, on the way in.
        $this->assertSame(
            '2026-08-03 15:30:00',
            $alert->refresh()->snoozed_until?->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_snoozing_an_alert_into_the_past_is_refused()
    {
        $user = User::factory()->create();
        [$reminder, $alert] = $this->alerted($user);

        $this->actingAs($user)
            ->from(route('today'))
            ->post(route('reminders.alerts.snooze', [$reminder, $alert]), [
                'until_date' => '2026-07-01',
                'until_time' => '09:00',
            ])
            ->assertSessionHasErrors('until_date');

        $this->assertNull($alert->refresh()->snoozed_until);
    }

    public function test_snoozing_an_alert_rejects_an_unknown_preset()
    {
        $user = User::factory()->create();
        [$reminder, $alert] = $this->alerted($user);

        $this->actingAs($user)
            ->from(route('today'))
            ->post(route('reminders.alerts.snooze', [$reminder, $alert]), ['preset' => 'forever'])
            ->assertSessionHasErrors('preset');

        $this->assertNull($alert->refresh()->snoozed_until);
    }

    public function test_an_alert_snooze_past_the_due_moment_is_accepted_and_simply_never_fires()
    {
        $user = User::factory()->create();
        [$reminder, $alert] = $this->alerted($user);

        // Three hours out on a reminder due in two: the strictly-before rule
        // means this alert will not fire again, and that is correct — the
        // main notification covers it.
        $this->actingAs($user)
            ->post(route('reminders.alerts.snooze', [$reminder, $alert]), ['preset' => '3h'])
            ->assertRedirect();

        $this->assertSame(
            '2026-08-03 17:00:00',
            $alert->refresh()->snoozed_until?->utc()->format('Y-m-d H:i:s'),
        );

        Carbon::setTestNow(Carbon::parse('2026-08-03 17:00:00', 'UTC'));

        $this->artisan('reminders:send-due')->assertSuccessful();

        $this->assertDatabaseCount('reminder_alert_dispatches', 0);
    }

    public function test_a_household_member_can_snooze_a_shared_reminders_alert()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        [$reminder, $alert] = $this->alerted($alice, shared: true);

        $this->actingAs($bob)
            ->post(route('reminders.alerts.snooze', [$reminder, $alert]), ['preset' => '10m'])
            ->assertRedirect();

        $this->assertSame(
            '2026-08-03 14:10:00',
            $alert->refresh()->snoozed_until?->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_stranger_cannot_snooze_someone_elses_alert()
    {
        $user = User::factory()->create();
        [$reminder, $alert] = $this->alerted($user);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('reminders.alerts.snooze', [$reminder, $alert]), ['preset' => '10m'])
            ->assertForbidden();

        $this->assertNull($alert->refresh()->snoozed_until);
    }

    public function test_a_household_member_cannot_snooze_a_private_reminders_alert()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        [$reminder, $alert] = $this->alerted($alice);

        $this->actingAs($bob)
            ->post(route('reminders.alerts.snooze', [$reminder, $alert]), ['preset' => '10m'])
            ->assertForbidden();
    }

    public function test_an_alert_belonging_to_another_reminder_is_a_404()
    {
        $user = User::factory()->create();
        [$mine] = $this->alerted($user);
        [, $theirAlert] = $this->alerted($user);

        // Scoped bindings: the pair has to be a real pair.
        $this->actingAs($user)
            ->post(route('reminders.alerts.snooze', [$mine, $theirAlert]), ['preset' => '10m'])
            ->assertNotFound();

        $this->assertNull($theirAlert->refresh()->snoozed_until);
    }

    public function test_a_signed_alert_snooze_works_without_a_session()
    {
        $user = User::factory()->create();
        [$reminder, $alert] = $this->alerted($user);

        $this->post($this->signedUrl($alert, ['preset' => '10m']))->assertNoContent();

        $this->assertSame(
            '2026-08-03 14:10:00',
            $alert->refresh()->snoozed_until?->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertNull($reminder->refresh()->snoozed_until);
    }

    public function test_a_tampered_alert_snooze_signature_is_rejected()
    {
        $user = User::factory()->create();
        [, $alert] = $this->alerted($user);

        $this->post($this->signedUrl($alert, ['preset' => '10m']).'0')->assertForbidden();

        $this->assertNull($alert->refresh()->snoozed_until);
    }

    public function test_swapping_the_alert_snooze_preset_invalidates_the_signature()
    {
        $user = User::factory()->create();
        [, $alert] = $this->alerted($user);

        $url = str_replace(
            'preset=10m',
            'preset=tomorrow',
            $this->signedUrl($alert, ['preset' => '10m']),
        );

        $this->post($url)->assertForbidden();

        $this->assertNull($alert->refresh()->snoozed_until);
    }

    public function test_an_unsigned_alert_snooze_is_rejected()
    {
        $user = User::factory()->create();
        [, $alert] = $this->alerted($user);

        $this->post(route('notification-actions.alerts.snooze', $alert))->assertForbidden();

        $this->assertNull($alert->refresh()->snoozed_until);
    }

    public function test_a_validly_signed_but_unknown_alert_preset_is_refused()
    {
        $user = User::factory()->create();
        [, $alert] = $this->alerted($user);

        // A signature proves the value came from us, not that it still means
        // anything — the allow-list is checked either way.
        $this->post($this->signedUrl($alert, ['preset' => 'forever']))->assertStatus(422);

        $this->assertNull($alert->refresh()->snoozed_until);
    }

    public function test_a_signed_url_for_a_deleted_alert_is_a_404()
    {
        $user = User::factory()->create();
        [, $alert] = $this->alerted($user);

        $url = $this->signedUrl($alert, ['preset' => '10m']);
        $alert->delete();

        $this->post($url)->assertNotFound();
    }

    /**
     * A signed alert-snooze URL of the kind the pre-alert push carries.
     *
     * @param  array<string, scalar>  $extra
     */
    private function signedUrl(ReminderAlert $alert, array $extra = []): string
    {
        return URL::temporarySignedRoute(
            'notification-actions.alerts.snooze',
            Carbon::now()->addDays(ReminderPreAlertNotification::ACTION_URL_TTL_DAYS),
            ['alert' => $alert->id, ...$extra],
        );
    }
}
