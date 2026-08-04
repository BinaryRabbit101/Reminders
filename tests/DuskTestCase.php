<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    use DatabaseMigrations;

    /**
     * Emulate a phone viewport — and actually get one.
     *
     * Same clamp as every other sibling app in this workspace: Windows enforces
     * a ~500px minimum window width, which WebDriver's resize silently honors,
     * so `$browser->resize(375, 812)` proves nothing about a 375px layout. This
     * uses `Emulation.setDeviceMetricsOverride` (CDP) to override the layout
     * viewport instead, which is not subject to the window manager's minimum.
     *
     * Reminders is a phone-first PWA (today-view, widget) — most Dusk coverage
     * here should call this rather than the bare `resize()`.
     */
    protected function emulateMobileViewport(Browser $browser, int $width = 375, int $height = 812): void
    {
        (new ChromeDevToolsDriver($browser->driver))->execute(
            'Emulation.setDeviceMetricsOverride',
            [
                'width' => $width,
                'height' => $height,
                'deviceScaleFactor' => 1,
                'mobile' => true,
            ],
        );

        $this->viewportOverriddenBrowsers[] = $browser;
    }

    /**
     * Browsers this test put a device-metrics override on, so {@see tearDown()} can lift it.
     *
     * @var array<int, Browser>
     */
    protected array $viewportOverriddenBrowsers = [];

    /**
     * Lift any device-metrics override before the next test runs — Dusk reuses
     * the same browser session across tests, and a CDP override outlives the
     * test that set it.
     */
    protected function tearDown(): void
    {
        foreach ($this->viewportOverriddenBrowsers as $browser) {
            try {
                (new ChromeDevToolsDriver($browser->driver))
                    ->execute('Emulation.clearDeviceMetricsOverride', []);
            } catch (\Throwable) {
                // The browser may already be gone if the test failed hard.
            }
        }

        $this->viewportOverriddenBrowsers = [];

        parent::tearDown();
    }

    /**
     * Fail if the page scrolls sideways.
     *
     * Asserts the viewport actually is 375px before checking overflow —
     * otherwise this goes quietly green at desktop width and proves nothing.
     */
    protected function assertNoHorizontalOverflow(Browser $browser, string $where): void
    {
        [$scrollWidth, $clientWidth] = $browser->script([
            'return document.documentElement.scrollWidth;',
            'return document.documentElement.clientWidth;',
        ]);

        $this->assertLessThanOrEqual(
            375,
            $clientWidth,
            "The viewport is {$clientWidth}px, not 375px — the mobile emulation is not in "
                .'effect, so this test is not checking what it claims to check.',
        );

        $this->assertLessThanOrEqual(
            $clientWidth + 1,
            $scrollWidth,
            "{$where} scrolls horizontally at 375px: content is {$scrollWidth}px "
                ."inside a {$clientWidth}px viewport.",
        );
    }

    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    /**
     * Reset the dusk.sqlite file to a clean migrated state after the run, so a
     * failed run doesn't leave stray data for the next one to trip over.
     *
     * No `#[AfterClass]` attribute here — `tearDownAfterClass` is already a
     * PHPUnit template method by name, and PHPUnit 13 warns when the
     * attribute duplicates that.
     */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        Artisan::call('migrate:fresh');
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            '--window-size=960,540',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }

    /**
     * Determine whether the Dusk command has disabled headless mode.
     */
    protected function hasHeadlessDisabled(): bool
    {
        return isset($_SERVER['DUSK_HEADLESS_DISABLED']) ||
               isset($_ENV['DUSK_HEADLESS_DISABLED']);
    }

    /**
     * Determine if the browser window should start maximized.
     */
    protected function shouldStartMaximized(): bool
    {
        return true;
    }
}
