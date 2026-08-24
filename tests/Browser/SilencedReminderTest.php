<?php

namespace Tests\Browser;

use App\Models\Reminder;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The silence checkbox in the reminder sheet, and the glyph it puts on a row.
 *
 * Unlike the auto-complete toggle next to it, this control is always present
 * — a one-off is as silenceable as a series — so what is under test is the
 * round trip and the row marker rather than conditional rendering.
 *
 * Assertions on the checkbox go through `data-state` rather than
 * `assertChecked`: it is a reka-ui CheckboxRoot, which renders a
 * `<button role="checkbox">` and keeps the real input hidden for form
 * serialisation.
 */
class SilencedReminderTest extends DuskTestCase
{
    public function test_a_one_off_reminder_can_be_silenced_on_the_way_in()
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
                ->type('#title', 'Take the bins out')
                // No repeat unit chosen, and the control is there anyway —
                // the difference from the auto-complete toggle above it.
                ->assertMissing('[data-test="auto-complete-toggle"]')
                ->scrollIntoView('[data-test="silenced-toggle"]')
                ->assertAttribute(
                    '[data-test="silenced-toggle"]',
                    'data-state',
                    'unchecked',
                )
                ->click('[data-test="silenced-toggle"]')
                ->assertAttribute(
                    '[data-test="silenced-toggle"]',
                    'data-state',
                    'checked',
                )
                ->scrollIntoView('[data-test="save-reminder-button"]')
                ->click('[data-test="save-reminder-button"]')
                ->waitUntilMissing('#title')
                ->pause(500)
                ->assertSee('Take the bins out')
                // Silence is invisible everywhere else, so the row has to say
                // so — otherwise the only way to find out is to not be told.
                ->assertPresent('[data-test="silenced-glyph"]');
        });

        $this->assertDatabaseHas('reminders', [
            'user_id' => $user->id,
            'title' => 'Take the bins out',
            'repeat_unit' => null,
            'is_silenced' => true,
        ]);
    }

    public function test_the_form_reopens_on_a_saved_toggle_and_unsilencing_takes()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->silenced()
            ->create(['title' => 'Water the plants']);

        $this->browse(function (Browser $browser) use ($user, $reminder) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->assertSee('Water the plants')
                ->assertPresent('[data-test="silenced-glyph"]')
                ->click(sprintf('[aria-label="Edit %s"]', $reminder->title))
                ->waitFor('#title')
                ->pause(600)
                ->scrollIntoView('[data-test="silenced-toggle"]')
                // The round trip: what was saved is what the sheet reopens on.
                ->assertAttribute(
                    '[data-test="silenced-toggle"]',
                    'data-state',
                    'checked',
                )
                ->click('[data-test="silenced-toggle"]')
                ->assertAttribute(
                    '[data-test="silenced-toggle"]',
                    'data-state',
                    'unchecked',
                )
                ->scrollIntoView('[data-test="save-reminder-button"]')
                ->click('[data-test="save-reminder-button"]')
                ->waitUntilMissing('#title')
                ->pause(500)
                ->assertSee('Water the plants')
                ->assertMissing('[data-test="silenced-glyph"]');
        });

        $this->assertDatabaseHas('reminders', [
            'id' => $reminder->id,
            'is_silenced' => false,
        ]);
    }

    public function test_the_row_menu_silences_and_unsilences_in_one_tap()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->create(['title' => 'Call the dentist']);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->assertSee('Call the dentist')
                ->assertMissing('[data-test="silenced-glyph"]')
                // Reka UI's DropdownMenu can eat a click that lands before it
                // has attached its listeners (CLAUDE.md), so the trigger is
                // waited for rather than clicked straight off the render.
                ->waitFor('[data-test="snooze-menu-trigger"]')
                ->click('[data-test="snooze-menu-trigger"]')
                ->waitFor('[data-test="silence-toggle"]')
                ->assertSeeIn('[data-test="silence-toggle"]', 'Silence')
                ->click('[data-test="silence-toggle"]')
                ->waitFor('[data-test="silenced-glyph"]')
                // The same item, now reading the other way off the row it
                // just changed.
                ->click('[data-test="snooze-menu-trigger"]')
                ->waitFor('[data-test="silence-toggle"]')
                ->assertSeeIn('[data-test="silence-toggle"]', 'Unsilence')
                ->click('[data-test="silence-toggle"]')
                ->waitUntilMissing('[data-test="silenced-glyph"]');
        });

        $this->assertDatabaseHas('reminders', [
            'user_id' => $user->id,
            'title' => 'Call the dentist',
            'is_silenced' => false,
        ]);
    }

    public function test_an_ordinary_reminder_carries_no_silence_glyph()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->create(['title' => 'Call the dentist']);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->assertSee('Call the dentist')
                ->assertMissing('[data-test="silenced-glyph"]');
        });
    }
}
