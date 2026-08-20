<?php

namespace Tests\Browser;

use App\Models\Reminder;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The auto-complete checkbox in the reminder sheet.
 *
 * It only exists inside the repeat section, so what is under test is as much
 * its *absence* for a one-off as its behaviour for a series. Assertions go
 * through `data-state` rather than `assertChecked`: the control is a reka-ui
 * CheckboxRoot, which renders a `<button role="checkbox">` and keeps the real
 * input hidden for form serialisation.
 */
class AutoCompleteTest extends DuskTestCase
{
    public function test_the_checkbox_appears_once_a_repeat_unit_is_chosen_and_saves()
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
                // Nothing to roll on to yet, so nothing to offer.
                ->assertMissing('[data-test="auto-complete-toggle"]')
                ->scrollIntoView('[data-test="repeat-select"]')
                ->select('[data-test="repeat-select"]', 'day')
                ->waitFor('[data-test="auto-complete-toggle"]')
                ->assertAttribute(
                    '[data-test="auto-complete-toggle"]',
                    'data-state',
                    'unchecked',
                )
                ->scrollIntoView('[data-test="auto-complete-toggle"]')
                ->click('[data-test="auto-complete-toggle"]')
                ->assertAttribute(
                    '[data-test="auto-complete-toggle"]',
                    'data-state',
                    'checked',
                )
                ->scrollIntoView('[data-test="save-reminder-button"]')
                ->click('[data-test="save-reminder-button"]')
                ->waitUntilMissing('#title')
                ->pause(500)
                ->assertSee('Water the plants');
        });

        $this->assertDatabaseHas('reminders', [
            'user_id' => $user->id,
            'title' => 'Water the plants',
            'repeat_unit' => 'day',
            'auto_complete' => true,
        ]);
    }

    public function test_the_form_reopens_on_a_saved_toggle_and_unticking_it_takes()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->autoCompleting()
            ->create(['title' => 'Feed the cat']);

        $this->browse(function (Browser $browser) use ($user, $reminder) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->assertSee('Feed the cat')
                ->click(sprintf('[aria-label="Edit %s"]', $reminder->title))
                ->waitFor('#title')
                ->pause(600)
                ->scrollIntoView('[data-test="auto-complete-toggle"]')
                // The round trip: what was saved is what the sheet reopens on.
                ->assertAttribute(
                    '[data-test="auto-complete-toggle"]',
                    'data-state',
                    'checked',
                )
                ->click('[data-test="auto-complete-toggle"]')
                ->assertAttribute(
                    '[data-test="auto-complete-toggle"]',
                    'data-state',
                    'unchecked',
                )
                ->scrollIntoView('[data-test="save-reminder-button"]')
                ->click('[data-test="save-reminder-button"]')
                ->waitUntilMissing('#title')
                ->pause(500)
                ->assertSee('Feed the cat');
        });

        $this->assertDatabaseHas('reminders', [
            'id' => $reminder->id,
            'repeat_unit' => 'day',
            'auto_complete' => false,
        ]);
    }

    public function test_the_checkbox_is_absent_for_a_one_off_reminder()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Just this once']);

        $this->browse(function (Browser $browser) use ($user, $reminder) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->click(sprintf('[aria-label="Edit %s"]', $reminder->title))
                ->waitFor('#title')
                ->pause(600)
                ->assertPresent('[data-test="repeat-select"]')
                ->assertMissing('[data-test="auto-complete-toggle"]');
        });
    }
}
