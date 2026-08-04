<?php

namespace Tests\Browser;

use App\Models\Reminder;
use App\Models\ReminderList;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ListsTest extends DuskTestCase
{
    public function test_user_can_add_an_existing_reminder_to_a_list()
    {
        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create(['name' => 'Groceries']);
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Buy milk']);

        $this->browse(function (Browser $browser) use ($user, $reminder) {
            $browser->loginAs($user)
                ->visit('/lists')
                ->assertSee('Groceries')
                ->click('[aria-label="Add an existing reminder to Groceries"]')
                ->waitFor('[data-test="reminder-picker-search"]')
                ->assertSee('Buy milk')
                ->click("[data-test=\"reminder-picker-row-{$reminder->id}\"]")
                ->waitUntilMissing("[data-test=\"reminder-picker-row-{$reminder->id}\"]")
                ->click('[data-test="reminder-picker-done-button"]')
                ->pause(500)
                ->assertSee('1 reminder');
        });

        $this->assertSame($list->id, $reminder->refresh()->list_id);
    }

    public function test_the_picker_leaves_out_reminders_already_in_the_list()
    {
        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create(['name' => 'Errands']);
        Reminder::factory()->for($user)->create(['title' => 'Already filed', 'list_id' => $list->id]);
        Reminder::factory()->for($user)->create(['title' => 'Not filed yet']);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/lists')
                ->click('[aria-label="Add an existing reminder to Errands"]')
                ->waitFor('[data-test="reminder-picker-search"]')
                ->assertSee('Not filed yet')
                ->assertDontSee('Already filed');
        });
    }

    public function test_the_picker_can_be_searched()
    {
        $user = User::factory()->create();
        ReminderList::factory()->for($user)->create(['name' => 'Errands']);
        Reminder::factory()->for($user)->create(['title' => 'Buy milk']);
        Reminder::factory()->for($user)->create(['title' => 'Pay rent']);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/lists')
                ->click('[aria-label="Add an existing reminder to Errands"]')
                ->waitFor('[data-test="reminder-picker-search"]')
                ->assertSee('Buy milk')
                ->assertSee('Pay rent')
                ->type('[data-test="reminder-picker-search"]', 'milk')
                ->pause(300)
                ->assertSee('Buy milk')
                ->assertDontSee('Pay rent');
        });
    }
}
