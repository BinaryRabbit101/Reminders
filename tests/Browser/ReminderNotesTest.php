<?php

namespace Tests\Browser;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ReminderNotesTest extends DuskTestCase
{
    private const LONG_NOTES = 'FOUND 2026-08-24. Billed through the App Store on the 29th monthly, '
        .'which is why it was hidden inside the Apple line and missing from older averages. '
        .'This is a real productivity tool, not forgotten streaming, so it is a deliberate '
        .'decision rather than an automatic cut. The plan has plenty of margin, so keeping it '
        .'is affordable — but it is still the single largest subscription on the list and '
        .'deserves a conscious yes every few months instead of silent auto-renewal.';

    public function test_long_notes_clamp_and_expand_on_the_today_board()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->create([
            'title' => 'Decide on the subscription',
            'due_at' => Carbon::now()->subHours(2),
            'notes' => self::LONG_NOTES,
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)->visit('/today');

            $this->emulateMobileViewport($browser);

            $browser->pause(300)
                ->waitFor('[data-test="reminder-notes-toggle"]')
                ->assertSeeIn('[data-test="reminder-notes-toggle"]', 'Show more');

            $this->assertTrue(
                $this->notesAreClamped($browser),
                'Long notes should be visually clamped before expanding.',
            );

            $browser->click('[data-test="reminder-notes-toggle"]')
                ->waitForTextIn('[data-test="reminder-notes-toggle"]', 'Show less');

            $this->assertFalse(
                $this->notesAreClamped($browser),
                'Expanded notes should show the full text.',
            );

            // The full text is prose, not truncation — and it must not push
            // the card sideways on a phone.
            $this->assertNoHorizontalOverflow($browser, 'Today board with expanded notes');

            $browser->click('[data-test="reminder-notes-toggle"]')
                ->waitForTextIn('[data-test="reminder-notes-toggle"]', 'Show more');
        });
    }

    public function test_expanding_notes_does_not_open_the_edit_sheet()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->create([
            'title' => 'Decide on the subscription',
            'due_at' => Carbon::now()->addHours(2),
            'notes' => self::LONG_NOTES,
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)->visit('/today')
                ->waitFor('[data-test="reminder-notes-toggle"]')
                ->click('[data-test="reminder-notes-toggle"]')
                ->waitForTextIn('[data-test="reminder-notes-toggle"]', 'Show less')
                ->pause(200)
                ->assertMissing('[role="dialog"]');
        });
    }

    public function test_short_notes_render_without_a_toggle()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->create([
            'title' => 'Water the plants',
            'due_at' => Carbon::now()->addHours(2),
            'notes' => 'Just the balcony ones.',
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)->visit('/today')
                ->waitFor('[data-test="reminder-notes"]')
                ->assertSeeIn('[data-test="reminder-notes"]', 'Just the balcony ones.')
                ->assertMissing('[data-test="reminder-notes-toggle"]');
        });
    }

    public function test_long_notes_clamp_and_expand_on_the_reminders_index()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->create([
            'title' => 'Decide on the subscription',
            'due_at' => Carbon::now()->addDay(),
            'notes' => self::LONG_NOTES,
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)->visit('/reminders');

            $this->emulateMobileViewport($browser);

            $browser->pause(300)
                ->waitFor('[data-test="reminder-notes-toggle"]')
                ->assertSeeIn('[data-test="reminder-notes-toggle"]', 'Show more');

            $this->assertTrue(
                $this->notesAreClamped($browser),
                'Long notes should be visually clamped on the index.',
            );

            $browser->click('[data-test="reminder-notes-toggle"]')
                ->waitForTextIn('[data-test="reminder-notes-toggle"]', 'Show less');

            $this->assertFalse(
                $this->notesAreClamped($browser),
                'Expanded notes should show the full text on the index.',
            );

            $this->assertNoHorizontalOverflow($browser, 'Reminders index with expanded notes');
        });
    }

    /** Whether the notes element is hiding part of its content behind the clamp. */
    private function notesAreClamped(Browser $browser): bool
    {
        return (bool) $browser->script(
            'const el = document.querySelector(\'[data-test="reminder-notes"]\');'
            .'return el.scrollHeight > el.clientHeight + 1;'
        )[0];
    }
}
