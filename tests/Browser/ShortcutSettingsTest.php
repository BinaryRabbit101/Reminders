<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The phone-key panel on Settings → Reminders.
 *
 * The Pest suite already proves the route mints and rolls the token; what
 * only a browser can hold is that one panel hands out one key in the two
 * shapes its two surfaces need — and that the key printed on its own is the
 * same string as the one inside the widget's feed link. When those were two
 * different secrets, the widget link pasted into the Shortcut was refused,
 * which is the bug this panel exists to make impossible to have again.
 */
class ShortcutSettingsTest extends DuskTestCase
{
    public function test_a_new_account_is_offered_a_key_but_not_given_one()
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/settings/reminders')
                ->waitFor('[data-test="phone-key-panel"]')
                ->assertSee("Your phone's key")
                ->assertMissing('[data-test="phone-token"]')
                ->assertMissing('[data-test="widget-feed-url"]')
                ->assertSeeIn('[data-test="regenerate-phone-token-button"]', 'Generate phone key');
        });
    }

    public function test_one_key_is_shown_in_both_shapes()
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/settings/reminders')
                ->waitFor('[data-test="phone-key-panel"]')
                ->click('[data-test="regenerate-phone-token-button"]')
                ->waitFor('[data-test="phone-token"]');

            $token = (string) $user->refresh()->phone_token;

            $browser->assertSeeIn('[data-test="phone-token"]', $token)
                // The same string, inside the link the widget's CONFIG takes.
                ->assertSeeIn('[data-test="widget-feed-url"]', $token)
                ->assertSeeIn('[data-test="shortcut-endpoint"]', '/api/shortcut/reminders')
                ->assertSee('X-Shortcut-Token')
                ->assertSeeIn('[data-test="regenerate-phone-token-button"]', 'Generate a new key');
        });
    }

    public function test_rolling_the_key_replaces_it_everywhere_on_the_page()
    {
        $user = User::factory()->create();
        $user->regeneratePhoneToken();
        $first = (string) $user->phone_token;

        $this->browse(function (Browser $browser) use ($user, $first) {
            $browser->loginAs($user)
                ->visit('/settings/reminders')
                ->waitFor('[data-test="phone-token"]')
                ->assertSeeIn('[data-test="phone-token"]', $first)
                ->click('[data-test="regenerate-phone-token-button"]')
                ->waitUntilMissingText($first);

            $token = (string) $user->refresh()->phone_token;

            // Both places move together, because there is only one of them.
            $browser->assertSeeIn('[data-test="phone-token"]', $token)
                ->assertSeeIn('[data-test="widget-feed-url"]', $token);
        });
    }
}
