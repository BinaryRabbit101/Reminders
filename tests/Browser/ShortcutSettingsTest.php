<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The quick-add key panel on Settings → Reminders.
 *
 * The Pest suite already proves the route mints and rolls the token; what
 * only a browser can hold is that the panel says the right thing at each of
 * its two states, and that it sits *beside* the widget's rather than on top
 * of it — two credentials with two buttons, which is the whole reason there
 * are two columns behind them.
 */
class ShortcutSettingsTest extends DuskTestCase
{
    public function test_a_new_account_is_offered_a_key_but_not_given_one()
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/settings/reminders')
                ->waitFor('[data-test="shortcut-key-panel"]')
                ->assertSee('Quick add shortcut')
                // The endpoint is not a secret and shows from the start:
                // seeing where the button points is half of understanding it.
                ->assertSeeIn('[data-test="shortcut-endpoint"]', '/api/shortcut/reminders')
                ->assertMissing('[data-test="shortcut-token"]')
                ->assertSeeIn('[data-test="regenerate-shortcut-token-button"]', 'Generate shortcut key');
        });
    }

    public function test_generating_a_key_shows_it_and_leaves_the_widget_link_alone()
    {
        $user = User::factory()->create();
        $user->regenerateWidgetToken();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/settings/reminders')
                ->waitFor('[data-test="shortcut-key-panel"]')
                ->click('[data-test="regenerate-shortcut-token-button"]')
                ->waitFor('[data-test="shortcut-token"]')
                ->assertSeeIn('[data-test="shortcut-token"]', (string) $user->refresh()->shortcut_token)
                ->assertSee('X-Shortcut-Token')
                ->assertSeeIn('[data-test="regenerate-shortcut-token-button"]', 'Generate a new key')
                // The widget's link is untouched: pressing one revoke button
                // must not knock the other credential over.
                ->assertSeeIn('[data-test="widget-feed-url"]', (string) $user->widget_token);
        });
    }

    public function test_rolling_the_key_replaces_the_one_on_screen()
    {
        $user = User::factory()->create();
        $user->regenerateShortcutToken();
        $first = (string) $user->shortcut_token;

        $this->browse(function (Browser $browser) use ($user, $first) {
            $browser->loginAs($user)
                ->visit('/settings/reminders')
                ->waitFor('[data-test="shortcut-token"]')
                ->assertSeeIn('[data-test="shortcut-token"]', $first)
                ->click('[data-test="regenerate-shortcut-token-button"]')
                ->waitUntilMissingText($first)
                ->assertSeeIn('[data-test="shortcut-token"]', (string) $user->refresh()->shortcut_token);
        });
    }
}
