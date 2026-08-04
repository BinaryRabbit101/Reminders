<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\TestPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class SendTestPushCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webpush.vapid.public_key', 'test-public-key');
        config()->set('webpush.vapid.private_key', 'test-private-key');
        config()->set('webpush.vapid.subject', 'mailto:test@example.com');
    }

    public function test_it_fails_when_vapid_keys_are_missing()
    {
        config()->set('webpush.vapid.private_key', null);

        $this->artisan('push:test')
            ->expectsOutputToContain('VAPID keys are not configured')
            ->assertFailed();
    }

    public function test_it_fails_when_no_user_matches()
    {
        Notification::fake();

        $this->artisan('push:test', ['user' => 'nobody@example.com'])
            ->assertFailed();

        Notification::assertNothingSent();
    }

    public function test_it_fails_when_the_user_has_no_subscriptions()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->artisan('push:test', ['user' => $user->email])
            ->expectsOutputToContain('no push subscriptions')
            ->assertFailed();

        Notification::assertNothingSent();
    }

    public function test_it_sends_to_a_user_named_by_email()
    {
        Notification::fake();

        $user = User::factory()->create();
        $user->updatePushSubscription('https://example.test/push/1', 'key-1', 'auth-1');

        $this->artisan('push:test', ['user' => $user->email])->assertSuccessful();

        Notification::assertSentTo($user, TestPushNotification::class);
    }

    public function test_it_defaults_to_the_first_subscribed_user()
    {
        Notification::fake();

        User::factory()->create();

        $subscribed = User::factory()->create();
        $subscribed->updatePushSubscription('https://example.test/push/2', 'key-2', 'auth-2');

        $this->artisan('push:test')->assertSuccessful();

        Notification::assertSentTo($subscribed, TestPushNotification::class);
    }

    public function test_the_notification_targets_the_web_push_channel_and_deep_links_to_today()
    {
        $user = User::factory()->create();
        $notification = new TestPushNotification;

        $this->assertSame(
            [WebPushChannel::class],
            $notification->via($user),
        );

        $message = $notification->toWebPush($user, $notification)->toArray();

        $this->assertSame('Reminders', $message['title']);
        $this->assertSame(route('today'), $message['data']['url']);
    }
}
