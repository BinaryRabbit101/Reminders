<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Estate standard §6: the notification grant sits at the top of every
 * signed-in page while this device isn't subscribed, and "Not now" puts it
 * away on this device for 7 days.
 *
 * Headless Chrome on 127.0.0.1 has PushManager, a secure context and an
 * unanswered permission, so a fresh session is exactly "supported, not
 * subscribed" — the case the banner exists for.
 */
class NotificationGrantBannerTest extends DuskTestCase
{
    private const SNOOZE_KEY = 'reminders:push-banner-snoozed-until';

    public function test_the_banner_shows_for_an_unsubscribed_session_and_hides_after_not_now()
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/reminders')
                // "Not now" is per-device; clear any left over from another test.
                ->script('localStorage.removeItem("'.self::SNOOZE_KEY.'");');

            $browser->refresh()
                ->waitFor('[data-test="notification-grant-banner"]', 10)
                ->assertAttribute('[data-test="notification-grant-banner"]', 'data-variant', 'enable')
                ->assertSeeIn('[data-test="notification-grant-enable"]', 'Turn on notifications');

            // It tops the content: nothing of the page sits above it.
            $bannerFirst = $browser->script(
                'const banner = document.querySelector(\'[data-test="notification-grant-banner"]\');'
                .'const button = document.querySelector(\'[data-test="new-reminder-button"]\');'
                .'return banner.getBoundingClientRect().top < button.getBoundingClientRect().top;'
            )[0];
            $this->assertTrue($bannerFirst, 'The banner is not above the page content.');

            // Every signed-in page, not just the landing page.
            $browser->visit('/settings/profile')
                ->waitFor('[data-test="notification-grant-banner"]', 10);

            $browser->click('[data-test="notification-grant-not-now"]')
                ->waitUntilMissing('[data-test="notification-grant-banner"]');

            $snoozedFor = $browser->script(
                'return Number(localStorage.getItem("'.self::SNOOZE_KEY.'")) - Date.now();'
            )[0];
            $sevenDays = 7 * 24 * 60 * 60 * 1000;
            $this->assertGreaterThan($sevenDays - 60_000, $snoozedFor);
            $this->assertLessThanOrEqual($sevenDays, $snoozedFor);

            // It stays put away on this device across pages and reloads.
            $browser->visit('/reminders')
                ->waitFor('[data-test="new-reminder-button"]')
                ->pause(1000)
                ->assertMissing('[data-test="notification-grant-banner"]');

            $browser->script('localStorage.removeItem("'.self::SNOOZE_KEY.'");');
        });
    }

    public function test_on_a_phone_the_banner_sits_in_the_page_flow_with_16px_text()
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $this->emulateMobileViewport($browser);

            $browser->loginAs($user)
                ->visit('/reminders')
                ->script('localStorage.removeItem("'.self::SNOOZE_KEY.'");');

            $browser->refresh()
                ->waitFor('[data-test="notification-grant-banner"]', 10);

            [$position, $textSize, $buttonSize, $notNowSize] = $browser->script([
                'return getComputedStyle(document.querySelector(\'[data-test="notification-grant-banner"]\').parentElement).position;',
                'return parseFloat(getComputedStyle(document.querySelector(\'[data-test="notification-grant-banner"] p\')).fontSize);',
                'return parseFloat(getComputedStyle(document.querySelector(\'[data-test="notification-grant-enable"]\')).fontSize);',
                'return parseFloat(getComputedStyle(document.querySelector(\'[data-test="notification-grant-not-now"]\')).fontSize);',
            ]);

            $this->assertNotContains($position, ['fixed', 'sticky'], 'The banner must sit in the page flow.');
            $this->assertGreaterThanOrEqual(16, $textSize);
            $this->assertGreaterThanOrEqual(16, $buttonSize);
            $this->assertGreaterThanOrEqual(16, $notNowSize);

            $this->assertNoHorizontalOverflow($browser, 'The page with the notification banner');
        });
    }
}
