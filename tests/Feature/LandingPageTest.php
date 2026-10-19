<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reminders index is the app's one landing page. There used to be a
 * separate Today page at /today; phones still carry home-screen icons whose
 * start_url is /today, and pushes sent before the change still link there,
 * so the old address has to keep landing somewhere useful.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_old_today_address_redirects_to_the_reminders_page()
    {
        $this->actingAs(User::factory()->create())
            ->get('/today')
            ->assertRedirect('/reminders');
    }

    public function test_a_guest_on_the_old_today_address_is_asked_to_sign_in()
    {
        $this->get('/today')->assertRedirect(route('login'));
    }

    public function test_the_dashboard_redirects_to_the_reminders_page()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertRedirect('/reminders');
    }

    public function test_signing_in_lands_on_the_reminders_page()
    {
        $this->assertSame('/reminders', config('fortify.home'));
    }

    public function test_the_home_screen_app_opens_on_the_reminders_page()
    {
        $manifest = json_decode(
            (string) file_get_contents(public_path('manifest.webmanifest')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('/reminders', $manifest['start_url']);
    }

    public function test_pushes_open_the_reminders_page_by_default()
    {
        $worker = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringNotContainsString("'/today'", $worker);
        $this->assertStringContainsString("?? '/reminders'", $worker);
    }

    public function test_the_reminders_page_renders_for_a_signed_in_user()
    {
        $this->actingAs(User::factory()->create())
            ->get('/reminders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('reminders/Index'));
    }
}
