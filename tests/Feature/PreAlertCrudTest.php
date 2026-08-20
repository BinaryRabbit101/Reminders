<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\ReminderAlert;
use App\Models\User;
use App\Support\NotificationHistory;
use App\Support\ReminderPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pre-alerts through the reminder form: validation, the sync semantics that
 * keep an untouched alert's snooze alive, and how they are presented back.
 *
 * The sync rule under test throughout: an offset that is still ticked keeps
 * its **existing row**. Delete-and-recreate would silently un-snooze every
 * alert on every save.
 */
class PreAlertCrudTest extends TestCase
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

    public function test_a_reminder_can_be_created_with_pre_alerts()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Dentist',
            'due_date' => '2026-08-10',
            'due_time' => '09:00',
            'alerts' => [1440, 60],
        ])->assertRedirect(route('reminders.index'));

        $reminder = Reminder::query()->sole();

        // Ordered by horizon, whichever order the chips posted in.
        $this->assertSame([60, 1440], $reminder->alerts->pluck('offset_minutes')->all());
    }

    public function test_a_reminder_created_without_alerts_has_none()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Dentist',
            'due_date' => '2026-08-10',
        ])->assertRedirect(route('reminders.index'));

        $this->assertDatabaseCount('reminder_alerts', 0);
    }

    public function test_an_offset_outside_the_allow_list_is_rejected()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('reminders.index'))
            ->post(route('reminders.store'), [
                'title' => 'Dentist',
                'due_date' => '2026-08-10',
                'alerts' => [7],
            ])
            ->assertSessionHasErrors('alerts.0');

        $this->assertDatabaseCount('reminders', 0);
        $this->assertDatabaseCount('reminder_alerts', 0);
    }

    public function test_updating_adds_and_removes_alerts_without_touching_the_ones_that_stay()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()->addDay()]);

        $keep = ReminderAlert::factory()->for($reminder)->offset(60)->create();
        ReminderAlert::factory()->for($reminder)->offset(15)->create();

        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => $reminder->title,
            // The same local due moment the factory stored, so only the
            // alerts are under test here.
            'due_date' => $reminder->due_at->setTimezone((string) config('reminders.timezone'))->format('Y-m-d'),
            'due_time' => $reminder->due_at->setTimezone((string) config('reminders.timezone'))->format('H:i'),
            'alerts' => [60, 1440],
        ])->assertRedirect();

        $reminder->refresh();

        $this->assertSame([60, 1440], $reminder->alerts->pluck('offset_minutes')->all());
        // The surviving offset kept its row — the id is the proof, and it is
        // what any already-sent push's action URL points at.
        $this->assertSame($keep->id, $reminder->alerts->firstWhere('offset_minutes', 60)?->id);
    }

    public function test_an_untouched_alert_keeps_its_snooze_across_an_edit()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()->addDay()]);
        $alert = ReminderAlert::factory()->for($reminder)->offset(60)->create();

        $alert->snoozeUntil(Carbon::now()->addMinutes(30));

        $local = $reminder->due_at->setTimezone((string) config('reminders.timezone'));

        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => 'Renamed, same moment',
            'due_date' => $local->format('Y-m-d'),
            'due_time' => $local->format('H:i'),
            'alerts' => [60],
        ])->assertRedirect();

        $this->assertSame(
            '2026-08-03 14:30:00',
            $alert->refresh()->snoozed_until?->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_changing_the_due_moment_clears_every_alert_snooze()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()->addDay()]);
        $alert = ReminderAlert::factory()->for($reminder)->offset(60)->create();

        $alert->snoozeUntil(Carbon::now()->addMinutes(30));

        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => $reminder->title,
            'due_date' => '2026-08-12',
            'due_time' => '18:00',
            'alerts' => [60],
        ])->assertRedirect();

        // The snooze belonged to an occurrence that no longer exists; left
        // behind it would pin the alert to a past moment forever.
        $this->assertNull($alert->refresh()->snoozed_until);
    }

    public function test_updating_with_no_alerts_removes_them_all()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()->addDay()]);
        ReminderAlert::factory()->for($reminder)->offset(60)->create();

        $local = $reminder->due_at->setTimezone((string) config('reminders.timezone'));

        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => $reminder->title,
            'due_date' => $local->format('Y-m-d'),
            'due_time' => $local->format('H:i'),
        ])->assertRedirect();

        $this->assertDatabaseCount('reminder_alerts', 0);
    }

    public function test_the_index_presents_a_reminders_alerts_with_their_labels()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()->addDay()]);
        ReminderAlert::factory()->for($reminder)->offset(60)->create();
        ReminderAlert::factory()->for($reminder)->offset(10080)->create();

        $this->actingAs($user)->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page
                ->has('reminders.0.alerts', 2)
                ->where('reminders.0.alerts.0.offset_minutes', 60)
                ->where('reminders.0.alerts.0.label', '1 hour before')
                ->where('reminders.0.alerts.0.is_snoozed', false)
                ->where('reminders.0.alerts.0.snooze_label', null)
                ->where('reminders.0.alerts.1.label', '1 week before')
            );
    }

    public function test_a_snoozed_alert_is_badged_and_an_expired_one_is_not()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()->addDay()]);
        ReminderAlert::factory()->for($reminder)->offset(60)
            ->snoozedUntil(Carbon::now()->addHour())->create();
        ReminderAlert::factory()->for($reminder)->offset(120)
            ->snoozedUntil(Carbon::now()->subHour())->create();

        $this->actingAs($user)->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page
                ->where('reminders.0.alerts.0.is_snoozed', true)
                ->where('reminders.0.alerts.0.snooze_label', 'Snoozed until Mon, Aug 3, 10:00 AM')
                // Already expired: it is an alert about to fire, not a
                // snoozed one, so badging it would be a lie.
                ->where('reminders.0.alerts.1.is_snoozed', false)
                ->where('reminders.0.alerts.1.snooze_label', null)
            );
    }

    public function test_the_form_defaults_offer_every_allowed_horizon()
    {
        $user = User::factory()->create();

        $defaults = ReminderPresenter::for($user)->formDefaults($user);

        $this->assertSame([], $defaults['alerts']);
        $this->assertCount(count(ReminderAlert::OFFSETS), $defaults['alert_offsets']);
        $this->assertSame(['value' => 5, 'label' => '5 minutes before'], $defaults['alert_offsets'][0]);
        $this->assertSame(
            [
                '5 minutes before', '10 minutes before', '15 minutes before', '30 minutes before',
                '1 hour before', '2 hours before', '1 day before', '2 days before', '1 week before',
            ],
            array_column($defaults['alert_offsets'], 'label'),
        );
    }

    public function test_the_history_feed_says_what_a_pre_alert_was_alerting_about()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'title' => 'Take the bins out',
            'due_at' => Carbon::now()->addHour(),
        ]);
        ReminderAlert::factory()->for($reminder)->offset(60)->create();

        $this->artisan('reminders:send-due')->assertSuccessful();

        $entry = NotificationHistory::make()->openFor($user->fresh())['days'][0]['entries'][0];

        $this->assertSame('pre_alert', $entry['kind']);
        $this->assertSame('Take the bins out', $entry['title']);
        // Filed and stamped at the moment the alert went out...
        $this->assertSame('9:00 AM', $entry['time_label']);
        // ...and saying, in the line the page already renders, which of the
        // two notifications this was.
        $this->assertSame('Alerted 1 hour before Mon, Aug 3, 10:00 AM', $entry['due_label']);
        $this->assertNotNull($entry['reminder']);
    }

    public function test_a_due_notification_entry_is_unchanged_by_pre_alerts_existing()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'title' => 'Water the plants',
            'due_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('reminders:send-due')->assertSuccessful();

        $entry = NotificationHistory::make()->openFor($user->fresh())['days'][0]['entries'][0];

        $this->assertNull($entry['kind']);
        $this->assertSame($reminder->title, $entry['title']);
        $this->assertSame('Mon, Aug 3, 8:59 AM', $entry['due_label']);
    }
}
