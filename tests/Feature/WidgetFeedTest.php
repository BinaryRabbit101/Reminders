<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Reminder;
use App\Models\ReminderList;
use App\Models\ReminderListFiling;
use App\Models\User;
use App\Support\ListColor;
use App\Support\WidgetFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The home-screen widget's feed: who it answers, and what it says.
 *
 * The endpoint lives outside every middleware group the rest of the app runs
 * under, so the tests here have to hold two lines that nothing else does —
 * that a session is neither needed nor sufficient, and that the token is
 * compared without telling a stranger anything about the accounts behind it.
 */
class WidgetFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A user with a widget token already minted.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function tokenHolder(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->regenerateWidgetToken();

        return $user;
    }

    /** The feed URL as the settings page hands it out. */
    private function feedUrl(User $user): string
    {
        return route('widget.today', ['token' => $user->widget_token]);
    }

    // ---- Token authentication ------------------------------------------

    public function test_a_valid_token_is_answered()
    {
        $user = $this->tokenHolder();

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonStructure([
                'overdue_count',
                'today',
                'upcoming',
                'next_upcoming',
                'pending_total',
                'open_url',
            ]);
    }

    public function test_a_missing_token_is_refused()
    {
        $this->tokenHolder();

        $this->getJson(route('widget.today'))->assertForbidden();
    }

    public function test_an_empty_token_is_refused()
    {
        $this->tokenHolder();

        $this->getJson(route('widget.today', ['token' => '']))->assertForbidden();
    }

    public function test_a_wrong_token_is_refused()
    {
        $this->tokenHolder();

        $this->getJson(route('widget.today', [
            'token' => 'ZpVmfU1bpqgu8HvG5ZBI2n1GJNnspjgNxYbmzdgW82l2PURh',
        ]))->assertForbidden();
    }

    public function test_a_token_array_is_refused_rather_than_exploding()
    {
        // `?token[]=x` arrives as an array; the resolver must read that as
        // "no token" instead of tripping over a type error.
        $this->tokenHolder();

        $this->getJson(route('widget.today').'?token[]=x')->assertForbidden();
    }

    public function test_the_refusal_says_the_same_thing_however_it_is_wrong()
    {
        // No enumeration: a stranger must not be able to tell a missing token
        // from a wrong one, or a wrong one from a near miss.
        $user = $this->tokenHolder();

        $missing = $this->getJson(route('widget.today'));
        $wrong = $this->getJson(route('widget.today', ['token' => 'nope']));
        $nearMiss = $this->getJson(route('widget.today', [
            'token' => substr((string) $user->widget_token, 0, -1).'X',
        ]));

        // Status and message only: the suite runs with debug on, which adds a
        // trace the deployed app will not. The point is that all three are
        // the *same* refusal, not what else the debug renderer bolts on.
        foreach ([$missing, $wrong, $nearMiss] as $response) {
            $response->assertForbidden()
                ->assertJsonPath('message', 'Invalid widget token.');
        }
    }

    public function test_an_account_without_a_token_cannot_be_matched_by_an_empty_one()
    {
        // Every account starts with a null token; "no token" must never
        // resolve to "the account that has no token".
        User::factory()->create();

        $this->getJson(route('widget.today', ['token' => '']))->assertForbidden();
        $this->getJson(route('widget.today'))->assertForbidden();
    }

    public function test_regenerating_the_token_invalidates_the_old_one()
    {
        $user = $this->tokenHolder();
        $old = $this->feedUrl($user);

        $user->regenerateWidgetToken();

        $this->getJson($old)->assertForbidden();
        $this->getJson($this->feedUrl($user))->assertOk();
    }

    public function test_a_logged_in_session_is_not_a_substitute_for_the_token()
    {
        // The route sits outside the session stack on purpose: being signed
        // in in this browser says nothing about which phone is asking.
        $user = $this->tokenHolder();

        $this->actingAs($user)
            ->getJson(route('widget.today'))
            ->assertForbidden();
    }

    public function test_the_token_never_travels_out_on_a_serialized_user()
    {
        $user = $this->tokenHolder();

        $this->assertArrayNotHasKey('widget_token', $user->toArray());
    }

    // ---- Payload -------------------------------------------------------

    public function test_the_payload_buckets_overdue_today_and_upcoming()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $user = $this->tokenHolder();

        $this->remind($user, '2026-08-03 08:00', 'Take out bins');
        $this->remind($user, '2026-08-03 15:00', 'Call the vet');
        $this->remind($user, '2026-08-04 09:00', 'Water plants');

        $response = $this->getJson($this->feedUrl($user))->assertOk();

        $response->assertJsonPath('overdue_count', 1);
        $response->assertJsonPath('pending_total', 3);
        $response->assertJsonPath('today.0.title', 'Take out bins');
        $response->assertJsonPath('today.0.is_overdue', true);
        $response->assertJsonPath('today.0.time', '8:00 AM');
        $response->assertJsonPath('today.1.title', 'Call the vet');
        $response->assertJsonPath('today.1.is_overdue', false);
        $response->assertJsonCount(2, 'today');

        // Tomorrow's reminder is not a row, but it is what comes next.
        $response->assertJsonPath('next_upcoming.title', 'Call the vet');
        $response->assertJsonPath('next_upcoming.when', '3:00 PM');
    }

    public function test_next_upcoming_labels_tomorrow_by_name()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 22:00', 'America/Chicago'));

        $user = $this->tokenHolder();
        $this->remind($user, '2026-08-04 09:00', 'Water plants');

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonPath('next_upcoming.when', 'Tomorrow 9:00 AM')
            ->assertJsonPath('next_upcoming.title', 'Water plants')
            ->assertJsonCount(0, 'today');
    }

    public function test_next_upcoming_is_null_when_nothing_is_ahead()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $user = $this->tokenHolder();
        $this->remind($user, '2026-08-03 08:00', 'Take out bins');

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonPath('next_upcoming', null);
    }

    public function test_the_row_list_is_capped_but_the_counts_are_not()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $user = $this->tokenHolder();

        for ($i = 1; $i <= 9; $i++) {
            $this->remind($user, '2026-08-03 0'.$i.':00', "Overdue {$i}");
        }

        $response = $this->getJson($this->feedUrl($user))->assertOk();

        $response->assertJsonCount(WidgetFeed::MAX_ROWS, 'today');
        $response->assertJsonPath('overdue_count', 9);
        $response->assertJsonPath('pending_total', 9);
    }

    public function test_upcoming_fills_the_row_budget_todays_list_left_spare()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $user = $this->tokenHolder();

        // One row today, five rows of budget spare out of MAX_ROWS (6).
        $this->remind($user, '2026-08-03 15:00', 'Call the vet');
        $this->remind($user, '2026-08-04 09:00', 'Water plants');
        $this->remind($user, '2026-08-05 09:00', 'Pay rent');

        $response = $this->getJson($this->feedUrl($user))->assertOk();

        $response->assertJsonCount(1, 'today');
        $response->assertJsonCount(2, 'upcoming');
        $response->assertJsonPath('upcoming.0.title', 'Water plants');
        $response->assertJsonPath('upcoming.1.title', 'Pay rent');
        // Not today, so the label is a date, not a bare time — same rule an
        // overdue row from an earlier day follows.
        $response->assertJsonPath('upcoming.0.time', 'Aug 4');
    }

    public function test_upcoming_is_empty_when_todays_list_already_fills_the_row_budget()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $user = $this->tokenHolder();

        for ($i = 1; $i <= WidgetFeed::MAX_ROWS; $i++) {
            $this->remind($user, '2026-08-03 0'.$i.':00', "Today {$i}");
        }

        $this->remind($user, '2026-08-04 09:00', 'Water plants');

        $response = $this->getJson($this->feedUrl($user))->assertOk();

        $response->assertJsonCount(WidgetFeed::MAX_ROWS, 'today');
        $response->assertJsonCount(0, 'upcoming');
    }

    public function test_upcoming_never_exceeds_the_leftover_row_budget()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $user = $this->tokenHolder();

        // Four rows today leaves two spare out of MAX_ROWS (6).
        for ($i = 1; $i <= 4; $i++) {
            $this->remind($user, '2026-08-03 0'.$i.':00', "Today {$i}");
        }

        for ($i = 1; $i <= 5; $i++) {
            $this->remind($user, '2026-08-0'.($i + 4).' 09:00', "Later {$i}");
        }

        $response = $this->getJson($this->feedUrl($user))->assertOk();

        $response->assertJsonCount(4, 'today');
        $response->assertJsonCount(2, 'upcoming');
        $response->assertJsonPath('upcoming.0.title', 'Later 1');
        $response->assertJsonPath('upcoming.1.title', 'Later 2');
    }

    public function test_an_overdue_row_from_an_earlier_day_shows_its_date()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $user = $this->tokenHolder();
        $this->remind($user, '2026-08-01 09:00', 'Old news');

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonPath('today.0.time', 'Aug 1')
            ->assertJsonPath('today.0.is_overdue', true);
    }

    public function test_completed_reminders_are_left_out_entirely()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $user = $this->tokenHolder();
        $this->remind($user, '2026-08-03 08:00', 'Done already')
            ->forceFill(['completed_at' => Carbon::now()])
            ->save();

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonCount(0, 'today')
            ->assertJsonPath('overdue_count', 0)
            ->assertJsonPath('pending_total', 0);
    }

    public function test_a_snoozed_reminder_shows_at_its_snoozed_time()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $user = $this->tokenHolder();
        $this->remind($user, '2026-08-03 08:00', 'Snoozed one')
            ->snoozeUntil(Carbon::parse('2026-08-03 16:30', 'America/Chicago'));

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            // Snoozed into the future, so no longer overdue — the same
            // coalesce the board and the delivery engine read.
            ->assertJsonPath('overdue_count', 0)
            ->assertJsonPath('today.0.time', '4:30 PM')
            ->assertJsonPath('today.0.is_overdue', false);
    }

    public function test_open_url_points_at_the_configured_app_address()
    {
        config(['app.url' => 'https://minipc.jackal-hippocampus.ts.net:451/']);

        $user = $this->tokenHolder();

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonPath('open_url', 'https://minipc.jackal-hippocampus.ts.net:451/today');
    }

    // ---- Timezone ------------------------------------------------------

    public function test_times_are_formatted_on_the_token_holders_own_clock()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $central = $this->tokenHolder();
        $eastern = $this->tokenHolder(['timezone' => 'America/New_York']);

        foreach ([$central, $eastern] as $user) {
            $this->remind($user, '2026-08-03 20:00 UTC', 'Standup');
        }

        // 20:00 UTC is 3pm in Chicago and 4pm in New York.
        $this->getJson($this->feedUrl($central))
            ->assertJsonPath('today.0.time', '3:00 PM');

        $this->getJson($this->feedUrl($eastern))
            ->assertJsonPath('today.0.time', '4:00 PM');
    }

    public function test_spring_forward_keeps_a_chicago_morning_at_its_own_hour()
    {
        // 2026-03-08 is the US spring-forward: 09:00 Chicago that morning is
        // 14:00 UTC (CDT), an hour off what the day before would have been.
        // Formatting on a fixed offset would print 8:00 AM here.
        Carbon::setTestNow(Carbon::parse('2026-03-08 06:00', 'America/Chicago'));

        $user = $this->tokenHolder();
        $this->remind($user, '2026-03-08 14:00 UTC', 'Spring forward');

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonPath('today.0.time', '9:00 AM')
            ->assertJsonPath('next_upcoming.when', '9:00 AM');
    }

    public function test_fall_back_keeps_a_chicago_morning_at_its_own_hour()
    {
        // 2026-11-01, the other direction: 09:00 Chicago is 15:00 UTC (CST).
        Carbon::setTestNow(Carbon::parse('2026-11-01 06:00', 'America/Chicago'));

        $user = $this->tokenHolder();
        $this->remind($user, '2026-11-01 15:00 UTC', 'Fall back');

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonPath('today.0.time', '9:00 AM');
    }

    public function test_the_local_day_boundary_decides_what_counts_as_today()
    {
        // 23:30 Chicago on the 3rd is already 04:30 UTC on the 4th. Bucketing
        // on the UTC date would push this row off the widget entirely.
        Carbon::setTestNow(Carbon::parse('2026-08-03 20:00', 'America/Chicago'));

        $user = $this->tokenHolder();
        $this->remind($user, '2026-08-03 23:30', 'Late one');

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonCount(1, 'today')
            ->assertJsonPath('today.0.time', '11:30 PM');
    }

    // ---- Visibility ----------------------------------------------------

    public function test_a_household_members_shared_reminder_appears()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $partner = User::factory()->create();
        $user = $this->tokenHolder();

        $this->linkHousehold($partner, $user);

        $this->remind($partner, '2026-08-03 15:00', 'Shared errand', shared: true);
        $this->remind($partner, '2026-08-03 16:00', 'Their private thing');

        $response = $this->getJson($this->feedUrl($user))->assertOk();

        $response->assertJsonCount(1, 'today');
        $response->assertJsonPath('today.0.title', 'Shared errand');
        $response->assertJsonPath('pending_total', 1);
    }

    public function test_an_outsiders_reminders_never_appear()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $user = $this->tokenHolder();
        $stranger = User::factory()->create();

        $this->remind($stranger, '2026-08-03 15:00', 'Not yours', shared: true);

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonCount(0, 'today')
            ->assertJsonPath('pending_total', 0);
    }

    // ---- List colours --------------------------------------------------

    public function test_a_row_carries_its_list_colour_for_the_owner()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $user = $this->tokenHolder();
        $list = ReminderList::factory()->for($user)->colored(ListColor::Emerald)->create();

        $this->remind($user, '2026-08-03 15:00', 'Pick up parcel', list: $list);

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonPath('today.0.list_color', '#10b981');
    }

    public function test_a_shared_reminders_list_colour_reflects_the_viewers_own_filing_not_the_owners()
    {
        // Lists are personal: the owner's own filing never shows on the
        // partner's widget, but the partner's own independent filing of the
        // same shared reminder does.
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $partner = User::factory()->create();
        $user = $this->tokenHolder();

        $this->linkHousehold($partner, $user);

        $ownersList = ReminderList::factory()->for($partner)->colored(ListColor::Emerald)->create();
        $reminder = $this->remind($partner, '2026-08-03 15:00', 'Shared errand', shared: true, list: $ownersList);

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonPath('today.0.title', 'Shared errand')
            ->assertJsonPath('today.0.list_color', null);

        $usersOwnList = ReminderList::factory()->for($user)->colored(ListColor::Sky)->create();
        ReminderListFiling::create([
            'reminder_id' => $reminder->id,
            'user_id' => $user->id,
            'list_id' => $usersOwnList->id,
        ]);

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonPath('today.0.list_color', '#0ea5e9');
    }

    public function test_a_row_without_a_list_carries_a_null_colour()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Chicago'));

        $user = $this->tokenHolder();
        $this->remind($user, '2026-08-03 15:00', 'Unfiled');

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonPath('today.0.list_color', null);
    }

    // ---- Helpers -------------------------------------------------------

    /**
     * A pending reminder for a user, due at a local Chicago wall time unless
     * the string carries a zone of its own.
     */
    private function remind(
        User $user,
        string $dueAt,
        string $title,
        bool $shared = false,
        ?ReminderList $list = null,
    ): Reminder {
        return Reminder::factory()->for($user)->create([
            'title' => $title,
            'due_at' => Carbon::parse($dueAt, 'America/Chicago')->utc(),
            'is_shared' => $shared,
            'list_id' => $list?->id,
        ]);
    }

    /** Put two accounts in one household. */
    private function linkHousehold(User $first, User $second): void
    {
        $household = Household::factory()->create();

        foreach ([$first, $second] as $member) {
            $member->forceFill(['household_id' => $household->id])->save();
        }
    }
}
