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
