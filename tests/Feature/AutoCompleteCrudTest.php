<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\User;
use App\Support\ReminderPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The `auto_complete` toggle through the reminder form.
 *
 * The rule most of this class is about: the flag is a repeat field in
 * everything but name, so it is normalised to false whenever there is no rule
 * to roll on to — a one-off that ticked itself off the moment it fired would
 * simply vanish.
 */
class AutoCompleteCrudTest extends TestCase
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

    public function test_a_repeating_reminder_can_be_created_with_auto_complete()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Feed the cat',
            'due_date' => '2026-08-10',
            'due_time' => '18:00',
            'repeat_unit' => 'day',
            'auto_complete' => '1',
        ])->assertRedirect(route('reminders.index'));

        $this->assertTrue(Reminder::query()->sole()->auto_complete);
    }

    public function test_a_repeating_reminder_created_without_the_toggle_does_not_get_it()
    {
        $user = User::factory()->create();

        // What the form posts when the checkbox is left alone: nothing at all.
        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Feed the cat',
            'due_date' => '2026-08-10',
            'repeat_unit' => 'day',
        ])->assertRedirect(route('reminders.index'));

        $this->assertFalse(Reminder::query()->sole()->auto_complete);
    }

    public function test_a_one_off_reminder_never_stores_auto_complete()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Just once',
            'due_date' => '2026-08-10',
            'auto_complete' => '1',
        ])->assertRedirect(route('reminders.index'));

        $reminder = Reminder::query()->sole();

        $this->assertNull($reminder->repeat_unit);
        $this->assertFalse($reminder->auto_complete);
    }

    public function test_the_toggle_round_trips_through_an_update()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->repeating('day')->create();

        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => $reminder->title,
            'due_date' => '2026-09-01',
            'due_time' => '10:00',
            'repeat_unit' => 'day',
            'auto_complete' => '1',
        ])->assertRedirect();

        $this->assertTrue($reminder->refresh()->auto_complete);

        // And off again — "absent" has to mean "off", or unticking it from
        // the edit sheet would never take.
        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => $reminder->title,
            'due_date' => '2026-09-01',
            'due_time' => '10:00',
            'repeat_unit' => 'day',
        ])->assertRedirect();

        $this->assertFalse($reminder->refresh()->auto_complete);
    }

    public function test_turning_a_repeat_off_clears_auto_complete_with_the_rest_of_the_rule()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->autoCompleting()
            ->create();

        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => 'No longer daily',
            'due_date' => '2026-09-01',
            'due_time' => '10:00',
            // Still posted by a stale form; still meaningless without a rule.
            'auto_complete' => '1',
        ])->assertRedirect();

        $reminder->refresh();

        $this->assertNull($reminder->repeat_unit);
        $this->assertFalse($reminder->auto_complete);
    }

    public function test_the_toggle_must_be_a_boolean()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('reminders.index'))
            ->post(route('reminders.store'), [
                'title' => 'Nonsense',
                'due_date' => '2026-08-10',
                'repeat_unit' => 'day',
                'auto_complete' => 'maybe',
            ])
            ->assertSessionHasErrors('auto_complete');

        $this->assertDatabaseCount('reminders', 0);
    }

    public function test_the_index_carries_the_toggle_so_the_edit_sheet_can_reopen_on_it()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)
            ->repeating('day')
            ->autoCompleting()
            ->create(['title' => 'Feed the cat']);

        $this->actingAs($user)->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page
                ->where('reminders.0.auto_complete', true)
                ->where('reminders.0.is_recurring', true)
                ->etc()
            );
    }

    public function test_a_one_off_is_presented_as_not_auto_completing()
    {
        $reminder = Reminder::factory()->create();

        $this->assertFalse(ReminderPresenter::make()->present($reminder)['auto_complete']);
    }

    public function test_the_form_defaults_leave_the_toggle_off()
    {
        $user = User::factory()->create();

        $this->assertFalse(ReminderPresenter::for($user)->formDefaults($user)['auto_complete']);
    }
}
