<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AuthenticationTest extends DuskTestCase
{
    public function test_user_can_view_login_page()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->assertSee('Log in to your account')
                ->assertPresent('#email')
                ->assertPresent('#password')
                ->assertSee('Log in');
        });
    }

    public function test_user_can_login_and_see_today_board()
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('#email', $user->email)
                ->type('#password', 'password')
                ->click('[data-test="login-button"]')
                ->waitForLocation('/today')
                ->assertAuthenticated();
        });
    }

    public function test_user_cannot_login_with_wrong_password()
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('#email', $user->email)
                ->type('#password', 'not-the-password')
                ->click('[data-test="login-button"]')
                ->pause(1000)
                ->assertPathIs('/login')
                ->assertGuest();
        });
    }

    public function test_user_can_logout()
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/today')
                ->assertAuthenticated()
                ->waitFor('[data-test="sidebar-menu-button"]');

            // The dropdown occasionally eats a click that lands before Vue
            // finishes attaching its listeners just after navigation. A
            // single blind retry click isn't enough: if the first click DID
            // open the menu but just slower than expected, that retry click
            // closes it again, and no amount of extra waiting afterwards
            // helps. Instead, retry the click itself and re-check each time,
            // so a stray close is immediately followed by a reopen.
            $opened = false;

            for ($attempt = 1; $attempt <= 4 && ! $opened; $attempt++) {
                $browser->click('[data-test="sidebar-menu-button"]');

                try {
                    $browser->waitFor('[data-test="logout-button"]', 2);
                    $opened = true;
                } catch (\Throwable) {
                    // Not open yet after this click — try again.
                }
            }

            $browser->assertVisible('[data-test="logout-button"]')
                ->click('[data-test="logout-button"]')
                ->waitForLocation('/')
                ->assertGuest();
        });
    }
}
