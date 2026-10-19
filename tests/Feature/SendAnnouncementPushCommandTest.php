<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AnnouncementPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendAnnouncementPushCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webpush.vapid.public_key', 'test-public-key');
        config()->set('webpush.vapid.private_key', 'test-private-key');
        config()->set('webpush.vapid.subject', 'mailto:test@example.com');
    }

    private function subscribedUser(int $n): User
    {
        $user = User::factory()->create();
        $user->updatePushSubscription("https://example.test/push/{$n}", "key-{$n}", "auth-{$n}");

        return $user;
    }

    public function test_it_sends_to_every_named_user()
    {
        Notification::fake();

        $a = $this->subscribedUser(1);
        $b = $this->subscribedUser(2);

        $this->artisan('push:send', [
            'users' => [$a->email, (string) $b->id],
            '--title' => 'Walmart drawing open',
            '--body' => '30th Celebration ETB — enter now',
            '--url' => 'https://www.walmart.com/shop/collectibles/draw',
            '--tag' => 'walmart-draw-1',
        ])->assertSuccessful();

        Notification::assertSentTo([$a, $b], AnnouncementPushNotification::class);
    }

    public function test_it_skips_users_without_subscriptions_but_still_sends_to_the_rest()
    {
        Notification::fake();

        $unsubscribed = User::factory()->create();
        $subscribed = $this->subscribedUser(3);

        $this->artisan('push:send', [
            'users' => [$unsubscribed->email, $subscribed->email, 'nobody@example.com'],
            '--title' => 'T',
            '--body' => 'B',
        ])->assertSuccessful();

        Notification::assertSentTo($subscribed, AnnouncementPushNotification::class);
        Notification::assertNotSentTo($unsubscribed, AnnouncementPushNotification::class);
    }

    public function test_it_fails_when_nobody_could_be_reached()
    {
        Notification::fake();

        $this->artisan('push:send', ['users' => ['nobody@example.com'], '--title' => 'T', '--body' => 'B'])
            ->assertFailed();

        Notification::assertNothingSent();
    }

    public function test_it_requires_a_title_and_body()
    {
        Notification::fake();

        $user = $this->subscribedUser(4);

        $this->artisan('push:send', ['users' => [$user->email], '--title' => 'T'])
            ->expectsOutputToContain('--title and --body are required')
            ->assertFailed();

        Notification::assertNothingSent();
    }

    public function test_the_message_carries_the_url_and_tag()
    {
        $user = User::factory()->create();
        $notification = new AnnouncementPushNotification('Title', 'Body', 'https://www.walmart.com/x', 'tag-1');

        $message = $notification->toWebPush($user, $notification)->toArray();

        $this->assertSame('Title', $message['title']);
        $this->assertSame('Body', $message['body']);
        $this->assertSame('tag-1', $message['tag']);
        $this->assertSame('https://www.walmart.com/x', $message['data']['url']);
    }

    public function test_the_url_defaults_to_today()
    {
        $user = User::factory()->create();
        $notification = new AnnouncementPushNotification('Title', 'Body');

        $message = $notification->toWebPush($user, $notification)->toArray();

        $this->assertSame(route('reminders.index'), $message['data']['url']);
        $this->assertArrayNotHasKey('tag', $message);
    }
}
