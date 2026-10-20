<?php

namespace Tests\Browser;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The reminders page as the app's one landing page. There used to be a
 * separate Today page; it was folded in here, so the nav no longer offers it,
 * and /today lands here. (The push nudge Today carried is now the
 * notification grant banner on every page: NotificationGrantBannerTest.)
 *
 * The day-panel cards the Today board used to draw still live on, in the
 * calendar view, so their delete and phone-width checks moved there.
 */
class RemindersLandingTest extends DuskTestCase
{
    public function test_the_nav_has_no_today_item_and_the_brand_goes_to_reminders()
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->waitFor('[data-sidebar="content"]');

            $navTitles = $browser->script(
                'return Array.from(document.querySelectorAll(\'[data-sidebar="content"] a\'))'
                .'.map((a) => a.textContent.trim());'
            )[0];

            $this->assertContains('Reminders', $navTitles);
            $this->assertNotContains('Today', $navTitles);
            $this->assertNotContains('All Reminders', $navTitles);

            // Nothing anywhere on the page still links to the old address.
            $browser->assertMissing('a[href$="/today"]');

            // The logo in the sidebar header is the way home.
            $logoHref = $browser->script(
                'return document.querySelector(\'[data-sidebar="header"] a\').getAttribute("href");'
            )[0];

            $this->assertStringEndsWith('/reminders', $logoHref);
        });
    }

    public function test_the_old_today_address_lands_on_the_reminders_page()
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/today')
                ->assertPathIs('/reminders')
                ->waitForText('Reminders')
                ->assertPresent('[data-test="new-reminder-button"]');
        });
    }

    public function test_the_landing_page_shows_everything_pending_and_fits_a_phone()
    {
        $user = User::factory()->create();

        Reminder::factory()->for($user)->create([
            'title' => 'Overdue thing',
            'due_at' => Carbon::now()->subHour(),
        ]);
        Reminder::factory()->for($user)->create([
            'title' => 'Later thing',
            'due_at' => Carbon::now()->addHours(2),
        ]);
        // The reason Today went: anything past its one-week window was not
        // on the landing page at all.
        Reminder::factory()->for($user)->create([
            'title' => 'Three weeks out',
            'due_at' => Carbon::now()->addWeeks(3),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)->visit('/reminders');

            $this->emulateMobileViewport($browser);

            $browser->pause(300)
                ->assertSee('Overdue thing')
                ->assertSee('Later thing')
                ->assertSee('Three weeks out');

            $this->assertNoHorizontalOverflow($browser, 'Reminders landing page');
        });
    }

    public function test_a_reminder_can_be_deleted_from_the_calendar_day_panel()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'title' => 'Take out the trash',
            'due_at' => $this->today()->setTime(14, 0)->utc(),
        ]);

        $this->browse(function (Browser $browser) use ($user, $reminder) {
            $browser->loginAs($user)
                ->visit('/reminders?view=calendar')
                ->waitForTextIn('[data-test="calendar-day-panel"]', 'Take out the trash')
                // The same confirm step the list uses — one dialog
                // component, so the wording cannot drift between them.
                ->click(sprintf('[data-test="calendar-day-panel"] [aria-label="Delete %s"]', $reminder->title))
                ->waitFor('[data-test="confirm-delete-reminder-button"]')
                ->click('[data-test="confirm-delete-reminder-button"]')
                ->waitUntilMissing('[data-test="confirm-delete-reminder-button"]')
                ->waitUntilMissingText('Take out the trash');
        });

        $this->assertDatabaseMissing('reminders', ['id' => $reminder->id]);
    }

    public function test_the_day_panel_card_actions_survive_a_phone_viewport()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->create([
            'title' => 'A reminder with a fairly long title to crowd the row',
            'due_at' => $this->today()->setTime(14, 0)->utc(),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)->visit('/reminders?view=calendar');

            $this->emulateMobileViewport($browser);

            // The card carries a snooze menu and a delete button in its
            // right-hand stack; at 375px that is exactly where a row starts
            // pushing sideways.
            $browser->pause(300)
                ->waitFor('[data-test="calendar-day-panel"] [data-test="delete-reminder-button"]')
                ->assertPresent('[data-test="calendar-day-panel"] [data-test="snooze-menu-trigger"]');

            $this->assertNoHorizontalOverflow($browser, 'Calendar day panel card');
        });
    }

    private function today(): Carbon
    {
        return Carbon::now((string) config('reminders.timezone'))->startOfDay();
    }
}
