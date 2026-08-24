<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Reminder;
use App\Models\User;
use App\Support\ReminderPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The `is_silenced` toggle through both its surfaces: the reminder form, and
 * the one-tap item on a row's snooze menu.
 *
 * The rule this class exists to pin down, and the one that separates it from
 * {@see AutoCompleteCrudTest}: silence is *not* a repeat field. It means the
 * same thing on a one-off as on a series, so nothing normalises it away and
 * turning a repeat off leaves it exactly where the user put it.
 */
class SilencedReminderCrudTest extends TestCase
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

    public function test_a_reminder_can_be_created_silenced()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Take the bins out',
            'due_date' => '2026-08-10',
            'due_time' => '18:00',
            'is_silenced' => '1',
        ])->assertRedirect(route('reminders.index'));

        // A one-off, and it keeps the flag — the point of the whole class.
        $reminder = Reminder::query()->sole();
        $this->assertTrue($reminder->is_silenced);
        $this->assertNull($reminder->repeat_unit);
    }

    public function test_a_reminder_created_without_the_toggle_does_not_get_it()
    {
        $user = User::factory()->create();

        // What the form posts when the checkbox is left alone: nothing at all.
        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Take the bins out',
            'due_date' => '2026-08-10',
            'due_time' => '18:00',
        ])->assertRedirect(route('reminders.index'));

        $this->assertFalse(Reminder::query()->sole()->is_silenced);
    }

    public function test_the_toggle_round_trips_through_update_in_both_directions()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Take the bins out']);

        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => 'Take the bins out',
            'due_date' => '2026-08-10',
            'due_time' => '18:00',
            'is_silenced' => '1',
        ])->assertRedirect(route('reminders.index'));

        $this->assertTrue($reminder->refresh()->is_silenced);

        // And back off — an unticked checkbox posts nothing, so "absent"
        // has to read as "off" or un-silencing would never take.
        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => 'Take the bins out',
            'due_date' => '2026-08-10',
            'due_time' => '18:00',
        ])->assertRedirect(route('reminders.index'));

        $this->assertFalse($reminder->refresh()->is_silenced);
    }

    public function test_turning_a_repeat_off_leaves_silence_alone()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->silenced()
            ->create(['title' => 'Water the plants']);

        // Unlike `auto_complete`, which recurrenceAttributes() clears with the
        // rest of the rule, silence survives the switch to one-off.
        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => 'Water the plants',
            'due_date' => '2026-08-10',
            'due_time' => '18:00',
            'is_silenced' => '1',
        ])->assertRedirect(route('reminders.index'));

        $reminder->refresh();

        $this->assertNull($reminder->repeat_unit);
        $this->assertTrue($reminder->is_silenced);
    }

    public function test_a_non_boolean_toggle_is_rejected()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('reminders.index'))
            ->post(route('reminders.store'), [
                'title' => 'Take the bins out',
                'due_date' => '2026-08-10',
                'due_time' => '18:00',
                'is_silenced' => 'maybe',
            ])
            ->assertSessionHasErrors('is_silenced');
    }

    public function test_the_index_carries_the_toggle_so_the_edit_sheet_can_reopen_on_it()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->silenced()->create(['title' => 'Take the bins out']);

        $this->actingAs($user)->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page
                ->where('reminders.0.is_silenced', true)
                ->etc()
            );
    }

    public function test_an_ordinary_reminder_is_presented_as_not_silenced()
    {
        $reminder = Reminder::factory()->create();

        $this->assertFalse(ReminderPresenter::make()->present($reminder)['is_silenced']);
    }

    public function test_the_form_defaults_leave_the_toggle_off()
    {
        $user = User::factory()->create();

        $this->assertFalse(ReminderPresenter::for($user)->formDefaults($user)['is_silenced']);
    }

    public function test_the_menu_endpoint_toggles_silence_both_ways()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        // No desired state is posted — the server flips the column, so a row
        // rendered before someone else changed it cannot set it wrong.
        $this->actingAs($user)
            ->from(route('today'))
            ->post(route('reminders.silence', $reminder))
            ->assertRedirect(route('today'));

        $this->assertTrue($reminder->refresh()->is_silenced);

        $this->actingAs($user)->post(route('reminders.silence', $reminder));

        $this->assertFalse($reminder->refresh()->is_silenced);
    }

    public function test_silencing_from_the_menu_flashes_a_toast_naming_the_direction()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Take the bins out']);

        $this->actingAs($user)
            ->post(route('reminders.silence', $reminder))
            ->assertSessionHas('inertia.flash_data.toast.message', 'Silenced')
            ->assertSessionHas(
                'inertia.flash_data.toast.description',
                'No push notifications for Take the bins out.',
            );

        $this->actingAs($user)
            ->post(route('reminders.silence', $reminder))
            ->assertSessionHas('inertia.flash_data.toast.message', 'Unsilenced')
            ->assertSessionHas(
                'inertia.flash_data.toast.description',
                'Push notifications are back on for Take the bins out.',
            );
    }

    public function test_silencing_from_the_menu_moves_nothing_else()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->repeating('day')->create([
            'due_at' => Carbon::now()->addHour(),
            'snoozed_until' => Carbon::now()->addMinutes(30),
        ]);

        $this->actingAs($user)->post(route('reminders.silence', $reminder));

        // Silence is a statement about channels. The occurrence, the series
        // and the snooze all stay exactly where they were.
        $reminder->refresh();
        $this->assertSame(
            Carbon::now()->addHour()->format('Y-m-d H:i:s'),
            $reminder->due_at->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            Carbon::now()->addMinutes(30)->format('Y-m-d H:i:s'),
            $reminder->snoozed_until?->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertNull($reminder->completed_at);
    }

    public function test_guests_and_strangers_cannot_silence_a_reminder()
    {
        $reminder = Reminder::factory()->silenced()->create();

        $this->post(route('reminders.silence', $reminder))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->post(route('reminders.silence', $reminder))
            ->assertForbidden();

        // Untouched by either attempt.
        $this->assertTrue($reminder->refresh()->is_silenced);
    }

    public function test_a_household_member_can_silence_a_shared_reminder_for_both()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $reminder = Reminder::factory()->for($alice)->shared()->create();

        // Silence belongs to the reminder, not to whoever is looking at it,
        // so Bob switching it off switches it off for Alice too. Inherited
        // from the `update` ability rather than restated — one household rule.
        $this->actingAs($bob)->post(route('reminders.silence', $reminder))->assertRedirect();

        $this->assertTrue($reminder->refresh()->is_silenced);
    }

    public function test_a_household_member_cannot_silence_a_private_reminder()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $reminder = Reminder::factory()->for($alice)->create();

        $this->actingAs($bob)->post(route('reminders.silence', $reminder))->assertForbidden();

        $this->assertFalse($reminder->refresh()->is_silenced);
    }

    public function test_a_household_member_editing_a_shared_reminder_can_silence_it_for_everyone()
    {
        // `is_shared` is normalised back to false for an account with nobody
        // to share with, so the household is what makes this test about
        // silence rather than about sharing.
        $user = User::factory()->for(Household::factory())->create();
        $reminder = Reminder::factory()->for($user)->shared()->create([
            'title' => 'Take the bins out',
        ]);

        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => 'Take the bins out',
            'due_date' => '2026-08-10',
            'due_time' => '18:00',
            'is_shared' => '1',
            'is_silenced' => '1',
        ])->assertRedirect(route('reminders.index'));

        $reminder->refresh();

        // Silence is a property of the reminder, like sharing is — the two
        // compose rather than conflict.
        $this->assertTrue($reminder->is_shared);
        $this->assertTrue($reminder->is_silenced);
    }
}
