<?php

namespace Tests\Browser;

use App\Models\Reminder;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ReminderCrudTest extends DuskTestCase
{
    public function test_user_can_create_a_reminder()
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->click('[data-test="new-reminder-button"]')
                ->waitFor('#title')
                // The sheet slides in over 500ms (SheetContent.vue); a click
                // during that transition lands on a still-moving element.
                ->pause(600)
                ->type('#title', 'Water the plants')
                ->scrollIntoView('[data-test="save-reminder-button"]')
                ->click('[data-test="save-reminder-button"]')
                ->waitUntilMissing('#title')
                ->pause(500)
                ->assertSee('Water the plants');
        });

        $this->assertDatabaseHas('reminders', [
            'user_id' => $user->id,
            'title' => 'Water the plants',
        ]);
    }

    public function test_user_can_edit_a_reminder()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Original title']);

        $this->browse(function (Browser $browser) use ($user, $reminder) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->assertSee('Original title')
                ->click(sprintf('[aria-label="Edit %s"]', $reminder->title))
                ->waitFor('#title')
                ->pause(600)
                ->clear('#title')
                ->type('#title', 'Updated title')
                ->scrollIntoView('[data-test="save-reminder-button"]')
                ->click('[data-test="save-reminder-button"]')
                ->waitUntilMissing('#title')
                ->pause(500)
                ->assertSee('Updated title')
                ->assertDontSee('Original title');
        });

        $this->assertDatabaseHas('reminders', [
            'id' => $reminder->id,
            'title' => 'Updated title',
        ]);
    }

    public function test_user_can_delete_a_reminder()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Take out the trash']);

        $this->browse(function (Browser $browser) use ($user, $reminder) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->assertSee('Take out the trash')
                ->click(sprintf('[aria-label="Delete %s"]', $reminder->title))
                ->waitFor('[data-test="confirm-delete-reminder-button"]')
                ->click('[data-test="confirm-delete-reminder-button"]')
                ->pause(500)
                ->assertDontSee('Take out the trash');
        });

        $this->assertDatabaseMissing('reminders', ['id' => $reminder->id]);
    }
}
