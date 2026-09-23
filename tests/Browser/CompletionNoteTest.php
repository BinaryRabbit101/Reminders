<?php

namespace Tests\Browser;

use App\Models\Reminder;
use App\Models\ReminderCompletion;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * "How did it go?" in a real browser: the dialog the tick-box opens for a
 * reminder with `ask_for_note`, each of its three ways out, the note page a
 * push notification opens — and the ordinary reminder that must still tick
 * off in one tap with no dialog in sight.
 */
class CompletionNoteTest extends DuskTestCase
{
    private const DIALOG = '[data-test="completion-note-dialog"]';

    public function test_ticking_a_note_reminder_asks_how_it_went_and_keeps_the_note()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create([
            'title' => 'Ask about her favourite trip',
            'notes' => 'Which one, and why?',
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->click('[data-test="complete-toggle"]')
                ->waitFor(self::DIALOG)
                ->assertSeeIn(self::DIALOG, 'How did it go?')
                ->assertSeeIn('[data-test="completion-note-title"]', 'Ask about her favourite trip')
                ->assertSeeIn('[data-test="completion-note-reminder-notes"]', 'Which one, and why?')
                ->type('[data-test="completion-note-input"]', 'Portugal, for the food.')
                ->click('[data-test="completion-note-save"]')
                ->waitUntilMissing(self::DIALOG)
                ->waitUntilMissing('[data-test="complete-toggle"]');
        });

        $this->assertNotNull($reminder->fresh()->completed_at);
        $this->assertSame('Portugal, for the food.', ReminderCompletion::query()->sole()->note);
    }

    public function test_skip_note_completes_without_one()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create(['title' => 'Ask about her day']);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->click('[data-test="complete-toggle"]')
                ->waitFor(self::DIALOG)
                ->click('[data-test="completion-note-skip"]')
                ->waitUntilMissing(self::DIALOG)
                ->waitUntilMissing('[data-test="complete-toggle"]');
        });

        $this->assertNotNull($reminder->fresh()->completed_at);
        $this->assertNull(ReminderCompletion::query()->sole()->note);
    }

    public function test_cancel_leaves_the_reminder_unticked()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create(['title' => 'Ask about her day']);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->click('[data-test="complete-toggle"]')
                ->waitFor(self::DIALOG)
                ->click('[data-test="completion-note-cancel"]')
                ->waitUntilMissing(self::DIALOG)
                ->assertAttribute('[data-test="complete-toggle"]', 'aria-checked', 'false');
        });

        $this->assertNull($reminder->fresh()->completed_at);
        $this->assertSame(0, ReminderCompletion::query()->count());
    }

    public function test_an_ordinary_reminder_still_completes_in_one_tap()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Pay the water bill']);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->click('[data-test="complete-toggle"]')
                ->pause(700)
                ->assertMissing(self::DIALOG)
                ->assertMissing('[data-test="complete-toggle"]');
        });

        $this->assertNotNull($reminder->fresh()->completed_at);
        $this->assertNull(ReminderCompletion::query()->sole()->note);
    }

    public function test_the_note_page_saves_and_lands_on_today()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create([
            'title' => 'Ask about her favourite trip',
            'notes' => 'Which one, and why?',
        ]);

        $this->browse(function (Browser $browser) use ($user, $reminder) {
            $browser->loginAs($user)
                ->visit(route('reminders.note', [
                    'reminder' => $reminder,
                    'due' => $reminder->due_at->getTimestamp(),
                ], false))
                ->waitFor('[data-test="completion-note-form"]')
                ->assertSeeIn('[data-test="note-page-title"]', 'Ask about her favourite trip')
                ->assertSeeIn('[data-test="completion-note-reminder-notes"]', 'Which one, and why?')
                ->type('[data-test="completion-note-input"]', 'Lisbon.')
                ->click('[data-test="completion-note-save"]')
                ->waitForLocation('/today');
        });

        $this->assertNotNull($reminder->fresh()->completed_at);
        $this->assertSame('Lisbon.', ReminderCompletion::query()->sole()->note);
    }

    public function test_the_note_page_says_so_when_already_done()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->completed()->create();

        $this->browse(function (Browser $browser) use ($user, $reminder) {
            $browser->loginAs($user)
                ->visit(route('reminders.note', $reminder, false))
                ->waitFor('[data-test="note-page-done"]')
                ->assertSee('Already done')
                ->assertMissing('[data-test="completion-note-form"]');
        });
    }
}
