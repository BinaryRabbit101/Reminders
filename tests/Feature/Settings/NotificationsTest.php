<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_see_the_notification_settings_page()
    {
        $this->get(route('notifications.edit'))->assertRedirect(route('login'));
    }

    public function test_the_notification_settings_page_is_displayed()
    {
        $user = User::factory()->create();

        config()->set('webpush.vapid.public_key', 'test-public-key');

        $response = $this->actingAs($user)->get(route('notifications.edit'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Notifications')
                ->where('pushConfigured', true)
                ->where('subscriptionCount', 0)
            );
    }

    public function test_the_page_reports_how_many_devices_are_subscribed()
    {
        $user = User::factory()->create();
        $user->updatePushSubscription('https://example.test/push/1', 'key-1', 'auth-1');
        $user->updatePushSubscription('https://example.test/push/2', 'key-2', 'auth-2');

        $response = $this->actingAs($user)->get(route('notifications.edit'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->where('subscriptionCount', 2));
    }

    public function test_the_page_reports_when_vapid_keys_are_missing()
    {
        $user = User::factory()->create();

        config()->set('webpush.vapid.public_key', null);

        $response = $this->actingAs($user)->get(route('notifications.edit'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->where('pushConfigured', false));
    }
}
