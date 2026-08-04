<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TodayTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();

        config(['reminders.timezone' => self::TIMEZONE]);
    }

    public function test_guests_cannot_see_the_today_page()
    {
        $this->get(route('today'))->assertRedirect(route('login'));
    }

    public function test_the_page_renders_the_three_buckets_for_the_authenticated_user()
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:00', self::TIMEZONE));

        $user = User::factory()->create();

        Reminder::factory()->for($user)->dueLocal('2026-08-02 23:30')->create(['title' => 'Overdue one']);
        Reminder::factory()->for($user)->dueLocal('2026-08-03 21:00')->create(['title' => 'Later today']);
        Reminder::factory()->for($user)->dueLocal('2026-08-04 09:00')->create(['title' => 'Tomorrow']);
        Reminder::factory()->dueLocal('2026-08-03 21:00')->create(['title' => 'Someone else']);

        $this->actingAs($user)->get(route('today'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Today')
                ->has('board.overdue', 1)
                ->where('board.overdue.0.title', 'Overdue one')
                ->has('board.today', 1)
                ->where('board.today.0.title', 'Later today')
                ->where('board.today.0.due_time_label', '9:00 PM')
                ->has('board.upcoming', 1)
                ->where('board.upcoming.0.label', 'Tomorrow')
                ->where('board.upcoming.0.reminders.0.title', 'Tomorrow')
                ->where('board.today_label', 'Monday, August 3')
                ->where('timezone', self::TIMEZONE)
                ->has('defaults.due_date')
                ->has('defaults.due_time')
            );
    }

    public function test_the_page_is_empty_when_nothing_is_pending()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('today'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Today')
                ->has('board.overdue', 0)
                ->has('board.today', 0)
                ->has('board.upcoming', 0)
            );
    }

    public function test_creating_a_reminder_from_the_today_page_comes_back_to_it()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('today'))
            ->post(route('reminders.store'), [
                'title' => 'Added from Today',
                'due_date' => '2026-08-10',
                'due_time' => '09:00',
            ])
            ->assertRedirect(route('today'));

        $this->assertDatabaseHas('reminders', ['title' => 'Added from Today']);
    }
}
