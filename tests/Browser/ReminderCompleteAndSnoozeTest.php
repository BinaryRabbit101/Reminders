<?php

namespace Tests\Browser;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ReminderCompleteAndSnoozeTest extends DuskTestCase
{
    public function test_user_can_complete_a_reminder()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Pay the water bill']);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->assertAttribute('[data-test="complete-toggle"]', 'aria-checked', 'false')
                ->click('[data-test="complete-toggle"]')
                ->pause(700)
                ->assertAttribute('[data-test="complete-toggle"]', 'aria-checked', 'true');
        });

        $this->assertNotNull($reminder->fresh()->completed_at);
    }

    public function test_user_can_uncomplete_a_reminder()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->create([
            'title' => 'Already done',
            'completed_at' => Carbon::now()->subMinutes(5),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->assertAttribute('[data-test="complete-toggle"]', 'aria-checked', 'true')
                ->click('[data-test="complete-toggle"]')
                ->pause(700)
                ->assertAttribute('[data-test="complete-toggle"]', 'aria-checked', 'false');
        });
    }

    public function test_user_can_snooze_a_reminder()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'title' => 'Call the dentist',
            'due_at' => Carbon::now()->addMinutes(5),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->click('[data-test="snooze-menu-trigger"]')
                ->waitFor('[data-test="snooze-1h"]')
                ->click('[data-test="snooze-1h"]')
                ->pause(700)
                ->assertPresent('[data-test="snoozed-badge"]');
        });

        $this->assertNotNull($reminder->fresh()->snoozed_until);
    }
}
