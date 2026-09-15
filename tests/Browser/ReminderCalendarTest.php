<?php

namespace Tests\Browser;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The calendar view of the reminders page, in a real browser.
 *
 * Dates here are computed from the clock rather than fixed: the browser runs
 * against the real one, so the fixtures are pinned to *this* month and the
 * assertions ask for the cells that month actually has.
 */
class ReminderCalendarTest extends DuskTestCase
{
    public function test_user_can_switch_between_the_list_and_the_calendar()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->create([
            'title' => 'Water the plants',
            'due_at' => $this->today()->setTime(14, 0)->utc(),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->assertSee('Water the plants')
                ->assertMissing('[data-test="reminder-calendar"]')
                ->click('[data-test="view-calendar"]')
                ->waitFor('[data-test="reminder-calendar"]')
                ->assertSeeIn('[data-test="calendar-month-label"]', $this->today()->format('F Y'))
                // The month is in the URL with the filters, so the view can
                // be linked to and survives a reload.
                ->assertQueryStringHas('view', 'calendar')
                // Today's cell is the one the day panel opens on.
                ->assertSeeIn('[data-test="calendar-day-panel"]', 'Water the plants')
                ->click('[data-test="view-list"]')
                ->waitUntilMissing('[data-test="reminder-calendar"]')
                ->assertSee('Water the plants');
        });
    }

    public function test_picking_a_day_opens_it_in_the_panel()
    {
        $user = User::factory()->create();
        $other = $this->today()->addDays($this->today()->day > 15 ? -3 : 3);

        Reminder::factory()->for($user)->create([
            'title' => 'Dentist appointment',
            'due_at' => $other->copy()->setTime(9, 0)->utc(),
        ]);

        $this->browse(function (Browser $browser) use ($user, $other) {
            $browser->loginAs($user)
                ->visit('/reminders?view=calendar')
                ->waitFor('[data-test="reminder-calendar"]')
                ->click(sprintf('[data-test="calendar-day-%s"]', $other->format('Y-m-d')))
                ->waitForTextIn('[data-test="calendar-day-panel"]', 'Dentist appointment')
                ->assertSeeIn('[data-test="calendar-day-panel"]', $other->format('l, F j'));
        });
    }

    public function test_the_month_arrows_move_the_grid_and_project_a_repeating_reminder()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->repeating('day')->create([
            'title' => 'Feed the cat',
            'due_at' => $this->today()->setTime(8, 0)->utc(),
        ]);

        // The 15th of next month: always inside next month's grid, whatever
        // weekday the month starts on, and always a *projection* — the stored
        // occurrence is this month.
        $next = $this->today()->addMonthNoOverflow()->startOfMonth();
        $target = $next->copy()->setDay(15);

        $this->browse(function (Browser $browser) use ($user, $next, $target) {
            $browser->loginAs($user)
                ->visit('/reminders?view=calendar')
                ->waitFor('[data-test="reminder-calendar"]')
                ->click('[data-test="calendar-next-month"]')
                ->waitForTextIn('[data-test="calendar-month-label"]', $next->format('F Y'))
                ->click(sprintf('[data-test="calendar-day-%s"]', $target->format('Y-m-d')))
                ->waitForTextIn('[data-test="calendar-day-panel"]', 'Feed the cat')
                // A projection is not a row: it says what it repeats as, and
                // offers no tick, snooze or delete.
                ->assertSeeIn('[data-test="calendar-projected-entry"]', 'Every day')
                ->assertMissing('[data-test="calendar-day-panel"] [data-test="delete-reminder-button"]')
                // And "Today" is the way back, only offered once you have
                // left the current month.
                ->click('[data-test="calendar-today-link"]')
                ->waitForTextIn('[data-test="calendar-month-label"]', $this->today()->format('F Y'))
                ->assertMissing('[data-test="calendar-today-link"]');
        });
    }

    public function test_adding_from_a_day_opens_the_sheet_on_that_day()
    {
        $user = User::factory()->create();
        $day = $this->today()->addDays($this->today()->day > 15 ? -2 : 2);

        $this->browse(function (Browser $browser) use ($user, $day) {
            $browser->loginAs($user)
                ->visit('/reminders?view=calendar')
                ->waitFor('[data-test="reminder-calendar"]')
                ->click(sprintf('[data-test="calendar-day-%s"]', $day->format('Y-m-d')))
                ->click('[data-test="calendar-add-on-day"]')
                ->waitFor('#title')
                // The sheet slides in over 500ms (SheetContent.vue); a click
                // during that transition lands on a still-moving element.
                ->pause(600)
                ->assertInputValue('#due_date', $day->format('Y-m-d'))
                ->type('#title', 'Pick up the parcel')
                ->scrollIntoView('[data-test="save-reminder-button"]')
                ->click('[data-test="save-reminder-button"]')
                ->waitUntilMissing('#title')
                ->pause(500)
                // Asserted on the page rather than in the day panel: saving
                // is a redirect back, and the freshly mounted grid opens on
                // today again rather than on the day that was picked.
                ->assertSee('Pick up the parcel');
        });

        $this->assertDatabaseHas('reminders', [
            'user_id' => $user->id,
            'title' => 'Pick up the parcel',
        ]);
    }

    public function test_the_month_grid_fits_a_phone_viewport()
    {
        $user = User::factory()->create();

        // Seven cells across is the layout most likely to push sideways, so
        // the fixtures crowd it: a long title, and a day carrying more
        // entries than a cell will spell out.
        foreach (['Collect the prescription from the pharmacy', 'Second', 'Third', 'Fourth'] as $index => $title) {
            Reminder::factory()->for($user)->create([
                'title' => $title,
                'due_at' => $this->today()->setTime(9 + $index, 0)->utc(),
            ]);
        }

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)->visit('/reminders?view=calendar');

            $this->emulateMobileViewport($browser);

            $browser->waitFor('[data-test="reminder-calendar"]')
                ->pause(300)
                // At phone width a cell is dots and a count; the day panel
                // underneath is what carries the detail.
                ->assertSeeIn('[data-test="calendar-day-panel"]', 'Collect the prescription from the pharmacy');

            $this->assertNoHorizontalOverflow($browser, 'Reminders calendar');
        });
    }

    /**
     * Today, on the app's display clock — the calendar's cells are local
     * days, so the fixtures have to be built on the same calendar.
     */
    private function today(): Carbon
    {
        return Carbon::now((string) config('reminders.timezone'))->startOfDay();
    }
}
