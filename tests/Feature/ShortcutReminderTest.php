<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Reminder;
use App\Models\ReminderList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The quick-add endpoint the iPhone Shortcut posts to.
 *
 * Two lines matter more here than anywhere else in the suite. The first is
 * the one every token route holds: a refusal must say nothing about which
 * accounts exist. The second is the promise this endpoint shipped without and
 * had to be corrected to keep — the key from the widget's feed link works
 * here, and the key from here reads the feed. One phone, one key. There are
 * tests in both directions, because a second credential is exactly the sort
 * of thing that grows back.
 */
class ShortcutReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A user holding a phone key — the one the widget and the Shortcut share.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function keyHolder(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->regeneratePhoneToken();

        return $user;
    }

    /**
     * Post as the Shortcut does: JSON body, key in the header.
     *
     * @param  array<string, mixed>  $payload
     */
    private function quickAdd(User $user, array $payload = ['title' => 'Take out bins'])
    {
        return $this->withHeader('X-Shortcut-Token', (string) $user->phone_token)
            ->postJson(route('shortcut.reminders.store'), $payload);
    }

    // ---- Token authentication ------------------------------------------

    public function test_a_key_in_the_header_creates_a_reminder()
    {
        $user = $this->keyHolder();

        $this->quickAdd($user, ['title' => 'Take out bins', 'due_date' => '2026-09-03', 'due_time' => '17:00'])
            ->assertCreated();

        $this->assertDatabaseHas('reminders', [
            'user_id' => $user->id,
            'title' => 'Take out bins',
        ]);
    }

    public function test_a_key_in_the_body_is_accepted()
    {
        // The header is what the recipe uses, but a URL-only setup has to
        // keep working — the widget's link has always been shaped that way.
        $user = $this->keyHolder();

        $this->postJson(route('shortcut.reminders.store'), [
            'token' => $user->phone_token,
            'title' => 'Water plants',
        ])->assertCreated();

        $this->assertDatabaseHas('reminders', ['title' => 'Water plants']);
    }

    public function test_a_key_in_the_query_string_is_accepted()
    {
        $user = $this->keyHolder();

        $this->postJson(
            route('shortcut.reminders.store', ['token' => $user->phone_token]),
            ['title' => 'Call the vet'],
        )->assertCreated();

        $this->assertDatabaseHas('reminders', ['title' => 'Call the vet']);
    }

    public function test_a_missing_key_is_refused()
    {
        $this->keyHolder();

        $this->postJson(route('shortcut.reminders.store'), ['title' => 'Nope'])
            ->assertForbidden();

        $this->assertDatabaseCount('reminders', 0);
    }

    public function test_a_key_array_is_refused_rather_than_exploding()
    {
        // `token[]=x` arrives as an array; the resolver must read that as
        // "no key" instead of tripping over a type error.
        $this->keyHolder();

        $this->postJson(route('shortcut.reminders.store'), ['token' => ['x'], 'title' => 'Nope'])
            ->assertForbidden();
    }

    public function test_the_refusal_says_the_same_thing_however_it_is_wrong()
    {
        $user = $this->keyHolder();

        $missing = $this->postJson(route('shortcut.reminders.store'), ['title' => 'Nope']);
        $wrong = $this->postJson(route('shortcut.reminders.store'), ['token' => 'nope', 'title' => 'Nope']);
        $nearMiss = $this->postJson(route('shortcut.reminders.store'), [
            'token' => substr((string) $user->phone_token, 0, -1).'X',
            'title' => 'Nope',
        ]);

        foreach ([$missing, $wrong, $nearMiss] as $response) {
            $response->assertForbidden()
                ->assertJsonPath('message', 'Invalid token — copy it again from Settings → Reminders.');
        }
    }

    public function test_the_key_from_the_widget_link_creates_reminders_too()
    {
        // The point of there being one column. Somebody who has had the
        // widget set up for a month pastes *that* key into the Shortcut and
        // it works — pulling it out of the feed URL the settings page hands
        // out, exactly as they would by hand.
        $user = $this->keyHolder();

        parse_str((string) parse_url(route('widget.today', ['token' => $user->phone_token]), PHP_URL_QUERY), $query);

        $this->withHeader('X-Shortcut-Token', (string) $query['token'])
            ->postJson(route('shortcut.reminders.store'), ['title' => 'Take out bins'])
            ->assertCreated();

        $this->assertDatabaseCount('reminders', 1);
    }

    public function test_the_same_key_reads_the_widget_feed()
    {
        // And the same door in the other direction.
        $user = $this->keyHolder();

        $this->getJson(route('widget.today', ['token' => $user->phone_token]))
            ->assertOk();
    }

    public function test_a_key_is_revoked_by_generating_a_new_one()
    {
        $user = $this->keyHolder();
        $old = (string) $user->phone_token;

        $user->regeneratePhoneToken();

        $this->withHeader('X-Shortcut-Token', $old)
            ->postJson(route('shortcut.reminders.store'), ['title' => 'Nope'])
            ->assertForbidden();
    }

    public function test_rolling_the_key_revokes_both_surfaces_at_once()
    {
        // The cost of one key, and the reason the settings page says so out
        // loud: a roll that fixes one thing breaks the other until the new
        // value is pasted into it as well.
        $user = $this->keyHolder();
        $old = (string) $user->phone_token;

        $user->regeneratePhoneToken();

        $this->getJson(route('widget.today', ['token' => $old]))->assertForbidden();
        $this->withHeader('X-Shortcut-Token', $old)
            ->postJson(route('shortcut.reminders.store'), ['title' => 'Nope'])
            ->assertForbidden();
    }

    // ---- When the reminder lands ---------------------------------------

    public function test_the_due_moment_is_read_on_the_accounts_own_timezone()
    {
        // 5pm in Chicago is 10pm UTC — the stored column is UTC, and the
        // phone sent nothing but wall-clock parts.
        $user = $this->keyHolder(['timezone' => 'America/Chicago']);

        $this->quickAdd($user, [
            'title' => 'Take out bins',
            'due_date' => '2026-09-03',
            'due_time' => '17:00',
        ])->assertCreated();

        $this->assertSame(
            '2026-09-03 22:00:00',
            Reminder::sole()->due_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_missing_time_uses_the_accounts_default_hour()
    {
        $user = $this->keyHolder(['timezone' => 'America/Chicago', 'default_time' => '08:30']);

        $this->quickAdd($user, ['title' => 'Take out bins', 'due_date' => '2026-09-03'])
            ->assertCreated();

        $this->assertSame(
            '08:30',
            Reminder::sole()->due_at->setTimezone('America/Chicago')->format('H:i'),
        );
    }

    public function test_a_twelve_hour_time_is_accepted()
    {
        // What an iPhone set to 12-hour time hands you out of Format Date.
        $user = $this->keyHolder(['timezone' => 'America/Chicago']);

        $this->quickAdd($user, [
            'title' => 'Take out bins',
            'due_date' => '2026-09-03',
            'due_time' => '5:00 PM',
        ])->assertCreated();

        $this->assertSame(
            '17:00',
            Reminder::sole()->due_at->setTimezone('America/Chicago')->format('H:i'),
        );
    }

    public function test_the_narrow_no_break_space_ios_puts_before_am_is_accepted()
    {
        // Since iOS 17 the system time formatter separates the time from
        // AM/PM with U+202F, not a space. It is indistinguishable on screen,
        // so a phone doing exactly what the recipe said would have been
        // refused for a character nobody can see.
        $user = $this->keyHolder(['timezone' => 'America/Chicago']);

        foreach (["8:30\u{202F}AM", "8:30\u{00A0}AM"] as $posted) {
            Reminder::query()->delete();

            $this->quickAdd($user, [
                'title' => 'Emily appointment',
                'due_date' => '2026-10-22',
                'due_time' => $posted,
            ])->assertCreated();

            $this->assertSame(
                '08:30',
                Reminder::sole()->due_at->setTimezone('America/Chicago')->format('H:i'),
            );
        }
    }

    public function test_midnight_and_noon_survive_the_twelve_hour_conversion()
    {
        $user = $this->keyHolder(['timezone' => 'America/Chicago']);

        foreach ([['12:15 AM', '00:15'], ['12:15 PM', '12:15']] as [$posted, $expected]) {
            Reminder::query()->delete();

            $this->quickAdd($user, [
                'title' => 'Take out bins',
                'due_date' => '2026-09-03',
                'due_time' => $posted,
            ])->assertCreated();

            $this->assertSame(
                $expected,
                Reminder::sole()->due_at->setTimezone('America/Chicago')->format('H:i'),
            );
        }
    }

    public function test_a_missing_date_lands_on_today_while_the_hour_is_still_ahead()
    {
        // 9am in Chicago, default hour 5pm: still to come, so today.
        Carbon::setTestNow('2026-09-03 14:00:00');
        $user = $this->keyHolder(['timezone' => 'America/Chicago', 'default_time' => '17:00']);

        $this->quickAdd($user)->assertCreated();

        $this->assertSame(
            '2026-09-03 17:00',
            Reminder::sole()->due_at->setTimezone('America/Chicago')->format('Y-m-d H:i'),
        );
    }

    public function test_a_missing_date_rolls_to_tomorrow_once_the_hour_has_passed()
    {
        // 9pm in Chicago. Today's 5pm is gone; a reminder due four hours ago
        // is an alarm, not a reminder.
        Carbon::setTestNow('2026-09-04 02:00:00');
        $user = $this->keyHolder(['timezone' => 'America/Chicago', 'default_time' => '17:00']);

        $this->quickAdd($user)->assertCreated();

        $this->assertSame(
            '2026-09-04 17:00',
            Reminder::sole()->due_at->setTimezone('America/Chicago')->format('Y-m-d H:i'),
        );
    }

    public function test_a_time_without_a_date_decides_the_day_itself()
    {
        // 9pm local, asked for 10pm: that is tonight, not tomorrow night —
        // the posted time decides, not the account's default hour.
        Carbon::setTestNow('2026-09-04 02:00:00');
        $user = $this->keyHolder(['timezone' => 'America/Chicago', 'default_time' => '17:00']);

        $this->quickAdd($user, ['title' => 'Lock up', 'due_time' => '22:00'])->assertCreated();

        $this->assertSame(
            '2026-09-03 22:00',
            Reminder::sole()->due_at->setTimezone('America/Chicago')->format('Y-m-d H:i'),
        );
    }

    public function test_an_explicitly_past_date_is_left_alone()
    {
        // Somebody who typed a date meant it; the app holds overdue rows.
        Carbon::setTestNow('2026-09-04 02:00:00');
        $user = $this->keyHolder(['timezone' => 'America/Chicago']);

        $this->quickAdd($user, ['title' => 'Log the mileage', 'due_date' => '2026-08-30'])
            ->assertCreated();

        $this->assertSame(
            '2026-08-30',
            Reminder::sole()->due_at->setTimezone('America/Chicago')->format('Y-m-d'),
        );
    }

    // ---- Lists, sharing, notes -----------------------------------------

    public function test_a_list_is_matched_by_name_regardless_of_case()
    {
        $user = $this->keyHolder();
        $list = ReminderList::factory()->for($user)->create(['name' => 'Home']);

        $this->quickAdd($user, ['title' => 'Take out bins', 'list' => 'home'])
            ->assertCreated()
            ->assertJsonPath('list', 'Home');

        $this->assertSame($list->id, Reminder::sole()->list_id);
    }

    public function test_an_unknown_list_is_refused_rather_than_dropped()
    {
        $user = $this->keyHolder();

        $this->quickAdd($user, ['title' => 'Take out bins', 'list' => 'Garage'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('list');

        $this->assertDatabaseCount('reminders', 0);
    }

    public function test_another_accounts_list_cannot_be_filed_into()
    {
        $user = $this->keyHolder();
        $stranger = User::factory()->create();
        ReminderList::factory()->for($stranger)->create(['name' => 'Secret']);

        $this->quickAdd($user, ['title' => 'Take out bins', 'list' => 'Secret'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('list');
    }

    public function test_no_list_is_the_default()
    {
        $user = $this->keyHolder();

        $this->quickAdd($user)->assertCreated()->assertJsonPath('list', null);

        $this->assertNull(Reminder::sole()->list_id);
    }

    public function test_sharing_is_stored_for_a_household_member()
    {
        $household = Household::factory()->create();
        $user = $this->keyHolder(['household_id' => $household->id]);

        $this->quickAdd($user, ['title' => 'Take out bins', 'is_shared' => true])
            ->assertCreated()
            ->assertJsonPath('is_shared', true);

        $this->assertTrue(Reminder::sole()->is_shared);
    }

    public function test_sharing_is_ignored_without_a_household()
    {
        $user = $this->keyHolder();

        $this->quickAdd($user, ['title' => 'Take out bins', 'is_shared' => true])
            ->assertCreated()
            ->assertJsonPath('is_shared', false);

        $this->assertFalse(Reminder::sole()->is_shared);
    }

    public function test_notes_are_stored()
    {
        $user = $this->keyHolder();

        $this->quickAdd($user, ['title' => 'Take out bins', 'notes' => 'Green bin week'])
            ->assertCreated();

        $this->assertSame('Green bin week', Reminder::sole()->notes);
    }

    public function test_blank_notes_and_list_are_read_as_absent()
    {
        // Shortcuts posts empty strings for anything the user skipped, and
        // `nullable` only excuses a real null. For these two, skipped really
        // does mean none — unlike the due fields below.
        $user = $this->keyHolder(['timezone' => 'America/Chicago']);

        $this->quickAdd($user, [
            'title' => 'Take out bins',
            'notes' => '',
            'list' => '',
        ])->assertCreated();

        $this->assertNull(Reminder::sole()->notes);
        $this->assertNull(Reminder::sole()->list_id);
    }

    // ---- Empty is not the same as absent -------------------------------

    public function test_an_empty_time_is_refused_rather_than_defaulted()
    {
        // The 2026-09-02 bug, and the reason this rule exists: an 8:30 that
        // never arrived became a silent 9:00 — the account's default hour —
        // with a confirmation that read as though it had worked. A key the
        // Shortcut *sent* and left empty is a broken variable, not a choice.
        Carbon::setTestNow('2026-09-02 14:00:00');
        $user = $this->keyHolder(['timezone' => 'America/Chicago']);

        $this->quickAdd($user, [
            'title' => 'Emily appointment',
            'due_date' => '2026-10-22',
            'due_time' => '',
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.due_time.0',
                "The due time arrived empty — check the variable in the Shortcut's due_time field, or delete that field to use your default hour.",
            );

        $this->assertDatabaseCount('reminders', 0);
    }

    public function test_an_empty_date_is_refused_rather_than_defaulted()
    {
        $user = $this->keyHolder(['timezone' => 'America/Chicago']);

        $this->quickAdd($user, ['title' => 'Take out bins', 'due_date' => '', 'due_time' => '08:30'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_date');

        $this->assertDatabaseCount('reminders', 0);
    }

    public function test_a_whitespace_only_time_counts_as_empty()
    {
        $user = $this->keyHolder(['timezone' => 'America/Chicago']);

        $this->quickAdd($user, ['title' => 'Take out bins', 'due_time' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_time');
    }

    public function test_an_omitted_time_still_means_the_default_hour()
    {
        // The one-tap shortcut, which posts no due fields at all. Absent is
        // still a choice — only *empty* is a fault.
        Carbon::setTestNow('2026-09-02 14:00:00');
        $user = $this->keyHolder(['timezone' => 'America/Chicago', 'default_time' => '09:00']);

        $this->quickAdd($user, ['title' => 'Take out bins'])->assertCreated();

        $this->assertSame(
            '09:00',
            Reminder::sole()->due_at->setTimezone('America/Chicago')->format('H:i'),
        );
    }

    // ---- The reply -----------------------------------------------------

    public function test_the_reply_carries_a_ready_made_message()
    {
        // The Shortcut shows this string verbatim: it has no idea what
        // timezone the account keeps, and string assembly in the Shortcuts
        // editor is a dozen drag-and-drop actions.
        $user = $this->keyHolder(['timezone' => 'America/Chicago']);

        $this->quickAdd($user, [
            'title' => 'Take out bins',
            'due_date' => '2026-09-03',
            'due_time' => '17:00',
        ])
            ->assertCreated()
            ->assertJsonPath('title', 'Take out bins')
            ->assertJsonPath('due_label', 'Thu, Sep 3, 5:00 PM')
            ->assertJsonPath('message', 'Reminder set — Thu, Sep 3, 5:00 PM')
            ->assertJsonStructure(['id', 'title', 'due_at', 'due_label', 'list', 'is_shared', 'message']);
    }

    public function test_a_missing_title_is_a_readable_refusal()
    {
        $user = $this->keyHolder();

        $this->quickAdd($user, ['title' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_a_malformed_date_says_what_shape_it_wanted()
    {
        $user = $this->keyHolder();

        $this->quickAdd($user, ['title' => 'Take out bins', 'due_date' => '03/09/2026'])
            ->assertStatus(422)
            ->assertJsonPath('errors.due_date.0', 'Send the due date as YYYY-MM-DD, e.g. 2026-09-03.');
    }

    public function test_a_malformed_time_says_what_shape_it_wanted()
    {
        $user = $this->keyHolder();

        $this->quickAdd($user, ['title' => 'Take out bins', 'due_time' => 'half five'])
            ->assertStatus(422)
            ->assertJsonPath('errors.due_time.0', 'Send the due time as HH:MM, e.g. 17:00 or 5:00 PM.');
    }

    public function test_the_endpoint_is_throttled()
    {
        // A bearer token on an unauthenticated write route is exactly the
        // thing somebody would sit and hammer.
        $user = $this->keyHolder();

        for ($i = 0; $i < 20; $i++) {
            $this->quickAdd($user)->assertCreated();
        }

        $this->quickAdd($user)->assertStatus(429);
    }
}
