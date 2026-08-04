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
                ->waitFor('[data-test="sidebar-menu-button"]')
                ->click('[data-test="sidebar-menu-button"]')
                ->pause(500);

            // The dropdown occasionally doesn't open from a click that lands
            // before Vue finishes attaching its listeners just after
            // navigation — one retry click (after giving the first one a
            // real chance to animate in) is cheaper than a flaky suite.
            if ($browser->element('[data-test="logout-button"]') === null) {
                $browser->click('[data-test="sidebar-menu-button"]');
            }

            $browser->waitFor('[data-test="logout-button"]', 10)
                ->click('[data-test="logout-button"]')
                ->waitForLocation('/')
                ->assertGuest();
        });
    }
}
