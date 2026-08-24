<?php

namespace Tests\Browser;

use App\Models\Reminder;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class HistoryCompletionTest extends DuskTestCase
{
    public function test_completing_a_reminder_shows_up_in_history()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Renew car registration']);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->click('[data-test="complete-toggle"]')
                // Wait on the round trip actually landing rather than on a
                // fixed pause — under a full-suite load the completion can
                // outlast any number picked in advance, and navigating away
                // early is what made this test flaky. The row *leaving* is
                // the signal: `/reminders` hides completed reminders unless
                // `show_completed` is on, so there is no ticked toggle left
                // on the page to wait for.
                ->waitUntilMissing('[data-test="complete-toggle"]')
                ->visit('/history')
                ->waitForText('Renew car registration')
                ->assertPresent('[data-test="history-completed"]')
                ->assertSeeIn('[data-test="history-completed"]', 'Renew car registration')
                ->assertSeeIn('[data-test="history-completed"]', 'Completed');
        });
    }
}
