<?php

namespace Tests\Browser;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class PreAlertTest extends DuskTestCase
{
    public function test_user_can_add_a_pre_alert_and_the_row_shows_a_bell()
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
                ->type('#title', 'Leave for the airport')
                ->scrollIntoView('[data-test="alert-60"]')
                ->click('[data-test="alert-60"]')
                ->assertChecked('#alert_60')
                ->scrollIntoView('[data-test="save-reminder-button"]')
                ->click('[data-test="save-reminder-button"]')
                ->waitUntilMissing('#title')
                ->pause(500)
                ->assertSee('Leave for the airport')
                ->assertPresent('[data-test="alerts-glyph"]');
        });

        $reminder = Reminder::query()->where('title', 'Leave for the airport')->sole();

        $this->assertDatabaseHas('reminder_alerts', [
            'reminder_id' => $reminder->id,
            'offset_minutes' => 60,
        ]);
    }

    public function test_the_form_reopens_on_the_saved_pre_alerts_and_unticking_removes_them()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Take the bins out']);
        $reminder->alerts()->create(['offset_minutes' => 60]);

        $this->browse(function (Browser $browser) use ($user, $reminder) {
            $browser->loginAs($user)
                ->visit('/reminders')
                ->assertPresent('[data-test="alerts-glyph"]')
                ->click(sprintf('[aria-label="Edit %s"]', $reminder->title))
                ->waitFor('#title')
                ->pause(600)
                // The round trip: what was saved is what the sheet reopens on.
                ->assertChecked('#alert_60')
                ->assertNotChecked('#alert_1440')
                ->scrollIntoView('[data-test="alert-60"]')
                ->click('[data-test="alert-60"]')
                ->assertNotChecked('#alert_60')
                ->scrollIntoView('[data-test="save-reminder-button"]')
                ->click('[data-test="save-reminder-button"]')
                ->waitUntilMissing('#title')
                ->pause(500)
                ->assertSee('Take the bins out')
                ->assertMissing('[data-test="alerts-glyph"]');
        });

        $this->assertDatabaseMissing('reminder_alerts', ['reminder_id' => $reminder->id]);
    }

    public function test_today_board_marks_a_reminder_that_has_pre_alerts()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'title' => 'Board the train',
            'due_at' => Carbon::now()->addHours(2),
        ]);
        $reminder->alerts()->create(['offset_minutes' => 30]);

        Reminder::factory()->for($user)->create([
            'title' => 'No alerts on this one',
            'due_at' => Carbon::now()->addHours(3),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/today')
                ->assertSee('Board the train')
                ->assertSee('No alerts on this one')
                // One bell for one alerting reminder — the plain one has none.
                ->assertPresent('[data-test="alerts-glyph"]')
                ->assertCount('[data-test="alerts-glyph"]', 1);
        });
    }

    public function test_the_pre_alert_chips_fit_a_phone_viewport()
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders');

            $this->emulateMobileViewport($browser);

            $browser->pause(300)
                ->click('[data-test="new-reminder-button"]')
                ->waitFor('#title')
                ->pause(600)
                ->scrollIntoView('[data-test="alert-offsets"]')
                ->assertVisible('[data-test="alert-10080"]');

            $this->assertNoHorizontalOverflow($browser, 'The pre-alert chips');
        });
    }
}
