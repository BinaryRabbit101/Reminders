<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_see_the_reminders_page()
    {
        $this->get(route('reminders.index'))->assertRedirect(route('login'));
    }

    public function test_the_index_lists_only_the_authenticated_users_reminders()
    {
        $user = User::factory()->create();
        $mine = Reminder::factory()->for($user)->create(['title' => 'Mine']);
        Reminder::factory()->create(['title' => 'Theirs']);

        $response = $this->actingAs($user)->get(route('reminders.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reminders/Index')
                ->has('reminders', 1)
                ->where('reminders.0.id', $mine->id)
                ->where('reminders.0.title', 'Mine')
                ->where('timezone', config('reminders.timezone'))
                ->has('defaults.due_date')
                ->has('defaults.due_time')
            );
    }

    public function test_a_user_can_create_a_reminder()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Water the plants',
            'notes' => 'The big one by the window.',
            'due_date' => '2026-08-10',
            'due_time' => '09:00',
        ]);

        $response->assertRedirect(route('reminders.index'));

        $reminder = Reminder::query()->sole();

        $this->assertSame($user->id, $reminder->user_id);
        $this->assertSame('Water the plants', $reminder->title);
        $this->assertSame('The big one by the window.', $reminder->notes);
        // 09:00 America/Chicago in August (CDT, UTC-5) is 14:00 UTC.
        $this->assertSame('2026-08-10 14:00:00', $reminder->due_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_reminder_without_a_time_uses_the_configured_default()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Dentist',
            'due_date' => '2026-08-10',
        ])->assertRedirect(route('reminders.index'));

        $reminder = Reminder::query()->sole();

        $this->assertNull($reminder->notes);
        $this->assertSame(
            config('reminders.default_time'),
            $reminder->due_at->setTimezone((string) config('reminders.timezone'))->format('H:i'),
        );
    }

    public function test_a_local_time_round_trips_through_utc_storage()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Standing meeting',
            // Straddles a DST boundary: CDT before Nov 1 2026, CST after.
            'due_date' => '2026-12-15',
            'due_time' => '09:00',
        ]);

        // Stored UTC is offset by the winter (CST, UTC-6) rules...
        $reminder = Reminder::query()->sole();
        $this->assertSame('2026-12-15 15:00:00', $reminder->due_at->utc()->format('Y-m-d H:i:s'));

        // ...and comes back out of the index as the 09:00 that was typed.
        $this->actingAs($user)->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page
                ->where('reminders.0.due_date', '2026-12-15')
                ->where('reminders.0.due_time', '09:00')
            );
    }

    public function test_a_user_can_update_their_reminder()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'title' => 'Old title',
            'notes' => 'Old notes',
        ]);

        $response = $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => 'New title',
            'due_date' => '2026-09-01',
            'due_time' => '18:30',
        ]);

        $response->assertRedirect(route('reminders.index'));

        $reminder->refresh();

        $this->assertSame('New title', $reminder->title);
        $this->assertNull($reminder->notes);
        $this->assertSame('2026-09-01 23:30:00', $reminder->due_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_user_can_delete_their_reminder()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        $this->actingAs($user)
            ->delete(route('reminders.destroy', $reminder))
            ->assertRedirect(route('reminders.index'));

        $this->assertDatabaseMissing('reminders', ['id' => $reminder->id]);
    }

    public function test_a_user_cannot_update_another_users_reminder()
    {
        $reminder = Reminder::factory()->create(['title' => 'Not yours']);

        $this->actingAs(User::factory()->create())
            ->put(route('reminders.update', $reminder), [
                'title' => 'Hijacked',
                'due_date' => '2026-09-01',
                'due_time' => '10:00',
            ])
            ->assertForbidden();

        $this->assertSame('Not yours', $reminder->refresh()->title);
    }

    public function test_a_user_cannot_delete_another_users_reminder()
    {
        $reminder = Reminder::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('reminders.destroy', $reminder))
            ->assertForbidden();

        $this->assertDatabaseHas('reminders', ['id' => $reminder->id]);
    }

    public function test_creating_a_reminder_requires_a_title_and_a_valid_date()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('reminders.index'))
            ->post(route('reminders.store'), [
                'title' => '',
                'due_date' => 'not-a-date',
                'due_time' => '25:99',
            ])
            ->assertSessionHasErrors(['title', 'due_date', 'due_time']);

        $this->assertDatabaseCount('reminders', 0);
    }

    public function test_the_pending_and_due_scopes_filter_the_working_set()
    {
        $user = User::factory()->create();

        $overdue = Reminder::factory()->for($user)->overdue()->create();
        Reminder::factory()->for($user)->create(['due_at' => Carbon::now()->addDay()]);
        Reminder::factory()->for($user)->overdue()->completed()->create();

        $this->assertSame(2, Reminder::query()->pending()->count());
        $this->assertSame([$overdue->id], Reminder::query()->due()->pluck('id')->all());
    }
}
