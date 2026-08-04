<?php

namespace Tests\Feature\Settings;

use App\Models\Reminder;
use App\Models\User;
use App\Support\QuietHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Settings → Reminders: the page, and the round trip from a saved preference
 * back out to the surfaces that read it.
 */
class ReminderSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A complete, valid form payload — tests override the one field they are
     * about, so a rule added later cannot silently pass unfilled.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'timezone' => '',
            'default_time' => '',
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
            ...$overrides,
        ];
    }

    public function test_guests_cannot_see_the_reminder_settings_page()
    {
        $this->get(route('reminder-settings.edit'))->assertRedirect(route('login'));
    }

    public function test_a_new_account_is_on_the_app_defaults()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('reminder-settings.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Reminders')
                // Null means "no preference", which is what lets the select
                // reopen on the App default option.
                ->where('settings.timezone', null)
                ->where('settings.default_time', null)
                ->where('settings.quiet_hours_enabled', false)
                ->where('effective.timezone', config('reminders.timezone'))
                ->where('effective.default_time_label', '9:00 AM')
            );
    }

    public function test_the_page_offers_a_curated_timezone_list()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('reminder-settings.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Reminders')
                ->has('timezones', 8)
                ->where('timezones.0.value', 'America/New_York')
            );
    }

    public function test_a_user_can_choose_a_timezone_and_a_default_time()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('reminder-settings.update'), $this->payload([
                'timezone' => 'America/Denver',
                'default_time' => '07:30',
            ]))
            ->assertRedirect(route('reminder-settings.edit'));

        $user->refresh();

        $this->assertSame('America/Denver', $user->timezone);
        $this->assertSame('07:30', $user->default_time);
        $this->assertSame('America/Denver', $user->timezone());
        $this->assertSame('07:30', $user->defaultTime());
    }

    public function test_clearing_a_preference_falls_back_to_the_app_default()
    {
        $user = User::factory()->create([
            'timezone' => 'America/Denver',
            'default_time' => '07:30',
        ]);

        $this->actingAs($user)
            ->patch(route('reminder-settings.update'), $this->payload())
            ->assertRedirect(route('reminder-settings.edit'));

        $user->refresh();

        $this->assertNull($user->timezone);
        $this->assertNull($user->default_time);
        $this->assertSame(config('reminders.timezone'), $user->timezone());
        $this->assertSame(config('reminders.default_time'), $user->defaultTime());
    }

    public function test_a_timezone_outside_the_curated_list_is_rejected()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('reminder-settings.edit'))
            ->patch(route('reminder-settings.update'), $this->payload([
                'timezone' => 'Antarctica/Troll',
            ]))
            ->assertSessionHasErrors('timezone');

        $this->assertNull($user->refresh()->timezone);
    }

    public function test_a_malformed_default_time_is_rejected()
    {
        $this->actingAs(User::factory()->create())
            ->from(route('reminder-settings.edit'))
            ->patch(route('reminder-settings.update'), $this->payload([
                'default_time' => 'half past nine',
            ]))
            ->assertSessionHasErrors('default_time');
    }

    public function test_a_user_can_switch_quiet_hours_on()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('reminder-settings.update'), $this->payload([
                'quiet_hours_enabled' => '1',
                'quiet_hours_start' => '23:30',
                'quiet_hours_end' => '06:15',
            ]))
            ->assertRedirect(route('reminder-settings.edit'));

        $user->refresh();

        $this->assertTrue($user->quiet_hours_enabled);
        $this->assertSame('23:30', $user->quiet_hours_start);
        $this->assertSame('06:15', $user->quiet_hours_end);
        $this->assertTrue($user->quietHours()->isEnabled());
    }

    public function test_an_absent_checkbox_switches_quiet_hours_off()
    {
        // An unchecked checkbox posts nothing at all — "absent" has to mean
        // "off" or quiet hours could never be turned back off again.
        $user = User::factory()->create(['quiet_hours_enabled' => true]);

        $this->actingAs($user)
            ->patch(route('reminder-settings.update'), $this->payload())
            ->assertRedirect(route('reminder-settings.edit'));

        $this->assertFalse($user->refresh()->quiet_hours_enabled);
    }

    public function test_switching_quiet_hours_off_keeps_the_window_for_next_time()
    {
        // The form keeps the time inputs enabled precisely so this holds: a
        // disabled input posts nothing, and the absence would read as "no
        // window" and quietly reset a window somebody chose.
        $user = User::factory()->create([
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '23:30',
            'quiet_hours_end' => '06:15',
        ]);

        $this->actingAs($user)
            ->patch(route('reminder-settings.update'), $this->payload([
                'quiet_hours_start' => '23:30',
                'quiet_hours_end' => '06:15',
            ]))
            ->assertRedirect(route('reminder-settings.edit'));

        $user->refresh();

        $this->assertFalse($user->quiet_hours_enabled);
        $this->assertSame('23:30', $user->quiet_hours_start);
        $this->assertSame('06:15', $user->quiet_hours_end);
    }

    public function test_switching_quiet_hours_on_needs_a_window_with_two_ends()
    {
        $this->actingAs(User::factory()->create())
            ->from(route('reminder-settings.edit'))
            ->patch(route('reminder-settings.update'), $this->payload([
                'quiet_hours_enabled' => '1',
                'quiet_hours_start' => '22:00',
                'quiet_hours_end' => '22:00',
            ]))
            ->assertSessionHasErrors('quiet_hours_end');
    }

    public function test_switching_quiet_hours_on_requires_the_times()
    {
        $this->actingAs(User::factory()->create())
            ->from(route('reminder-settings.edit'))
            ->patch(route('reminder-settings.update'), $this->payload([
                'quiet_hours_enabled' => '1',
                'quiet_hours_start' => '',
                'quiet_hours_end' => '',
            ]))
            ->assertSessionHasErrors(['quiet_hours_start', 'quiet_hours_end']);
    }

    public function test_a_blank_window_with_quiet_hours_off_keeps_the_defaults()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('reminder-settings.update'), $this->payload([
                'quiet_hours_start' => '',
                'quiet_hours_end' => '',
            ]))
            ->assertRedirect(route('reminder-settings.edit'));

        $user->refresh();

        $this->assertSame(QuietHours::DEFAULT_START, $user->quiet_hours_start);
        $this->assertSame(QuietHours::DEFAULT_END, $user->quiet_hours_end);
    }

    public function test_a_user_cannot_change_another_accounts_settings()
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)
            ->patch(route('reminder-settings.update'), $this->payload([
                'timezone' => 'America/Denver',
            ]));

        $this->assertSame('America/Denver', $alice->refresh()->timezone);
        $this->assertNull($bob->refresh()->timezone);
    }

    // ---- The home-screen widget's feed link ----------------------------

    public function test_a_new_account_has_no_widget_link_yet()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('reminder-settings.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Reminders')
                ->where('widget.token', null)
                ->where('widget.feed_url', null)
            );
    }

    public function test_a_user_can_generate_a_widget_link()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('reminder-settings.widget-token'))
            ->assertRedirect(route('reminder-settings.edit'));

        $token = $user->refresh()->widget_token;

        $this->assertNotNull($token);
        $this->assertSame(User::WIDGET_TOKEN_LENGTH, strlen($token));

        // The page hands out the whole ready-to-paste URL, not a bare token.
        $this->actingAs($user)
            ->get(route('reminder-settings.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('widget.token', $token)
                ->where('widget.feed_url', route('widget.today', ['token' => $token]))
            );
    }

    public function test_generating_again_rolls_the_link()
    {
        $user = User::factory()->create();
        $user->regenerateWidgetToken();
        $first = $user->widget_token;

        $this->actingAs($user)
            ->post(route('reminder-settings.widget-token'))
            ->assertRedirect(route('reminder-settings.edit'));

        $this->assertNotSame($first, $user->refresh()->widget_token);
    }

    public function test_a_guest_cannot_generate_a_widget_link()
    {
        $this->post(route('reminder-settings.widget-token'))
            ->assertRedirect(route('login'));
    }

    public function test_generating_a_link_touches_nobody_elses()
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->post(route('reminder-settings.widget-token'));

        $this->assertNotNull($alice->refresh()->widget_token);
        $this->assertNull($bob->refresh()->widget_token);
    }

    public function test_a_chosen_timezone_decides_how_a_new_reminder_is_stored()
    {
        $user = User::factory()->create(['timezone' => 'America/New_York']);

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Standup',
            'due_date' => '2026-08-05',
            'due_time' => '09:00',
        ]);

        // 09:00 Eastern in August is 13:00 UTC — an hour off what the app
        // default (Central) would have stored.
        $this->assertSame(
            '2026-08-05 13:00:00',
            Reminder::query()->sole()->due_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_chosen_timezone_decides_how_a_reminder_is_read_back()
    {
        $user = User::factory()->create(['timezone' => 'America/New_York']);

        Reminder::factory()->for($user)->create([
            'due_at' => Carbon::parse('2026-08-05 13:00', 'UTC'),
        ]);

        $this->actingAs($user)
            ->get(route('reminders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('timezone', 'America/New_York')
                ->where('reminders.0.due_time', '09:00')
            );
    }

    public function test_a_chosen_default_time_lands_a_date_only_reminder()
    {
        $user = User::factory()->create(['default_time' => '06:45']);

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Bins',
            'due_date' => '2026-08-05',
        ]);

        $this->assertSame(
            '06:45',
            Reminder::query()->sole()->due_at->setTimezone($user->timezone())->format('H:i'),
        );
    }

    public function test_a_chosen_default_time_moves_tomorrow_morning()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 18:00', 'America/Chicago'));

        $user = User::factory()->create(['default_time' => '06:45']);
        $reminder = Reminder::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('reminders.snooze', $reminder), ['preset' => 'tomorrow'])
            ->assertRedirect();

        $this->assertSame(
            '2026-08-04 06:45',
            $reminder->refresh()->snoozed_until?->setTimezone('America/Chicago')->format('Y-m-d H:i'),
        );
    }

    public function test_tomorrow_morning_is_the_snoozing_users_own_morning()
    {
        // 23:30 in Chicago is already 00:30 on the 4th in New York, so the
        // two accounts' "tomorrow" are different calendar days: the 4th for
        // an account on the app default, the 5th for this one.
        Carbon::setTestNow(Carbon::parse('2026-08-03 23:30', 'America/Chicago'));

        $user = User::factory()->create(['timezone' => 'America/New_York']);
        $reminder = Reminder::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('reminders.snooze', $reminder), ['preset' => 'tomorrow'])
            ->assertRedirect();

        $this->assertSame(
            '2026-08-05 09:00',
            $reminder->refresh()->snoozed_until?->setTimezone('America/New_York')->format('Y-m-d H:i'),
        );
    }

    public function test_the_today_board_buckets_on_the_viewers_own_calendar()
    {
        // 23:30 Chicago on the 3rd is 00:30 on the 4th in New York. The same
        // reminder is therefore "later today" for one account and "tomorrow"
        // for the other — which is the whole point of the override.
        Carbon::setTestNow(Carbon::parse('2026-08-03 20:00', 'America/Chicago'));

        $central = User::factory()->create();
        $eastern = User::factory()->create(['timezone' => 'America/New_York']);

        foreach ([$central, $eastern] as $user) {
            Reminder::factory()->for($user)->create([
                'due_at' => Carbon::parse('2026-08-03 23:30', 'America/Chicago')->utc(),
            ]);
        }

        $this->actingAs($central)
            ->get(route('today'))
            ->assertInertia(fn ($page) => $page->has('board.today', 1)->has('board.upcoming', 0));

        $this->actingAs($eastern)
            ->get(route('today'))
            ->assertInertia(fn ($page) => $page->has('board.today', 0)->has('board.upcoming', 1));
    }
}
