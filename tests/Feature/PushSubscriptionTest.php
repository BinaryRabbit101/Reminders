<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}}
     */
    private function subscriptionPayload(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => 'BFakePublicKeyForTesting',
                'auth' => 'FakeAuthToken',
            ],
        ];
    }

    public function test_guests_cannot_store_a_push_subscription()
    {
        $this->post(route('push.store'), $this->subscriptionPayload())
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_a_user_can_store_a_push_subscription()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('notifications.edit'))
            ->post(route('push.store'), $this->subscriptionPayload());

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('notifications.edit'));

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $user->id,
            'subscribable_type' => $user->getMorphClass(),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'public_key' => 'BFakePublicKeyForTesting',
            'auth_token' => 'FakeAuthToken',
        ]);
    }

    public function test_storing_the_same_endpoint_twice_does_not_duplicate_the_subscription()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('push.store'), $this->subscriptionPayload());
        $this->actingAs($user)->post(route('push.store'), $this->subscriptionPayload());

        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertSame(1, $user->pushSubscriptions()->count());
    }

    public function test_storing_a_subscription_requires_an_endpoint_and_keys()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('notifications.edit'))
            ->post(route('push.store'), ['endpoint' => 'https://example.test/push'])
            ->assertSessionHasErrors(['keys.p256dh', 'keys.auth']);

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_a_user_can_delete_their_push_subscription()
    {
        $user = User::factory()->create();
        $payload = $this->subscriptionPayload();

        $this->actingAs($user)->post(route('push.store'), $payload);

        $response = $this->actingAs($user)
            ->from(route('notifications.edit'))
            ->delete(route('push.destroy'), ['endpoint' => $payload['endpoint']]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('notifications.edit'));

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_deleting_a_subscription_requires_an_endpoint()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('notifications.edit'))
            ->delete(route('push.destroy'), [])
            ->assertSessionHasErrors('endpoint');
    }

    public function test_guests_cannot_delete_a_push_subscription()
    {
        $user = User::factory()->create();
        $payload = $this->subscriptionPayload();

        $this->actingAs($user)->post(route('push.store'), $payload);

        $this->app['auth']->logout();

        $this->delete(route('push.destroy'), ['endpoint' => $payload['endpoint']])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('push_subscriptions', 1);
    }
}
