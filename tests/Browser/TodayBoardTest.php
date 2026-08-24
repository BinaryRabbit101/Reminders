<?php

namespace Tests\Browser;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class TodayBoardTest extends DuskTestCase
{
    public function test_today_board_buckets_reminders_and_fits_a_phone_viewport()
    {
        $user = User::factory()->create();

        Reminder::factory()->for($user)->create([
            'title' => 'Overdue thing',
            'due_at' => Carbon::now()->subHour(),
        ]);
        Reminder::factory()->for($user)->create([
            'title' => 'Later today thing',
            'due_at' => Carbon::now()->addHours(2),
        ]);
        Reminder::factory()->for($user)->create([
            'title' => 'Next week thing',
            'due_at' => Carbon::now()->addDays(3),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/today');

            $this->emulateMobileViewport($browser);

            $browser->pause(300)
                ->assertPresent('[data-test="section-overdue"]')
                ->assertSeeIn('[data-test="section-overdue"]', 'Overdue thing')
                ->assertPresent('[data-test="section-today"]')
                ->assertSeeIn('[data-test="section-today"]', 'Later today thing')
                ->assertPresent('[data-test="section-upcoming"]')
                ->assertSeeIn('[data-test="section-upcoming"]', 'Next week thing');

            $this->assertNoHorizontalOverflow($browser, 'Today board');
        });
    }

    public function test_a_reminder_can_be_deleted_from_the_today_board()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'title' => 'Take out the trash',
            'due_at' => Carbon::now()->addHours(2),
        ]);

        $this->browse(function (Browser $browser) use ($user, $reminder) {
            $browser->loginAs($user)
                ->visit('/today')
                ->assertSee('Take out the trash')
                // The same confirm step the reminders index uses — one
                // dialog component, so the wording cannot drift between them.
                ->click(sprintf('[aria-label="Delete %s"]', $reminder->title))
                ->waitFor('[data-test="confirm-delete-reminder-button"]')
                ->click('[data-test="confirm-delete-reminder-button"]')
                ->waitUntilMissing('[data-test="confirm-delete-reminder-button"]')
                ->assertDontSee('Take out the trash');
        });

        $this->assertDatabaseMissing('reminders', ['id' => $reminder->id]);
    }

    public function test_the_delete_button_survives_a_phone_viewport()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->create([
            'title' => 'A reminder with a fairly long title to crowd the row',
            'due_at' => Carbon::now()->addHours(2),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)->visit('/today');

            $this->emulateMobileViewport($browser);

            // The card gained a second control in its right-hand stack; at
            // 375px that is exactly where a row starts pushing sideways.
            $browser->pause(300)
                ->assertPresent('[data-test="delete-reminder-button"]')
                ->assertPresent('[data-test="snooze-menu-trigger"]');

            $this->assertNoHorizontalOverflow($browser, 'Today board with delete');
        });
    }

    public function test_today_board_shows_all_clear_state()
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/today')
                ->assertPresent('[data-test="today-all-clear"]');
        });
    }
}
