<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Support\FakePushTransport;
use Tests\TestCase;

/**
 * Reminders as the estate push service: `POST /api/push` and
 * `GET /api/push/recipients`, behind the estate-standard `X-Api-Token`.
 *
 * The push transport is faked one layer below the notification — a
 * {@see FakePushTransport} stands in for minishlink's WebPush inside the real
 * channel — so these tests exercise the real message, the real report
 * handler and the real sent/failed tally without touching the network.
 */
class PushApiTest extends TestCase
{
    use RefreshDatabase;

    private FakePushTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webpush.vapid.public_key', 'test-public-key');
        config()->set('webpush.vapid.private_key', 'test-private-key');
        config()->set('webpush.vapid.subject', 'mailto:test@example.com');

        $this->transport = new FakePushTransport;
        $this->app->instance(WebPushChannel::class, new WebPushChannel($this->transport, $this->app->make(ReportHandler::class)));
    }

    /**
     * The owner and co-owner: one household, each with a phone key, the
     * owner with one subscribed phone and the co-owner with two devices.
     *
     * @return array{0: User, 1: User}
     */
    private function household(): array
    {
        $household = Household::factory()->create();

        $owner = User::factory()->create(['name' => 'Keith', 'household_id' => $household->id]);
        $owner->regeneratePhoneToken();
        $owner->updatePushSubscription('https://push.test/owner-phone', 'key-1', 'auth-1');

        $partner = User::factory()->create(['name' => 'Emily', 'household_id' => $household->id]);
        $partner->updatePushSubscription('https://push.test/partner-phone', 'key-2', 'auth-2');
        $partner->updatePushSubscription('https://push.test/partner-ipad', 'key-3', 'auth-3');

        return [$owner, $partner];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function push(User $user, array $payload): TestResponse
    {
        return $this->withHeader('X-Api-Token', (string) $user->phone_token)
            ->postJson(route('api.push.send'), $payload);
    }

    // ---- Sending -------------------------------------------------------

    public function test_both_reaches_every_device_in_the_household(): void
    {
        [$owner] = $this->household();

        $this->push($owner, [
            'title' => 'Tornado Warning',
            'body' => 'Oklahoma County. Until 9:45 PM.',
            'url' => 'https://minipc.example.ts.net:464/',
            'tag' => 'nws-123',
            'to' => 'both',
        ])
            ->assertOk()
            ->assertJson(['ok' => true, 'sent' => 3, 'failed' => 0, 'skipped' => []])
            ->assertJsonPath('message', 'Sent to 3 devices.');

        $this->assertCount(3, $this->transport->queued);

        $payload = $this->transport->queued[0]['payload'];
        $this->assertSame('Tornado Warning', $payload['title']);
        $this->assertSame('Oklahoma County. Until 9:45 PM.', $payload['body']);
        $this->assertSame('nws-123', $payload['tag']);
        $this->assertSame('https://minipc.example.ts.net:464/', $payload['data']['url']);
    }

    public function test_me_reaches_only_the_token_holder(): void
    {
        [$owner] = $this->household();

        $this->push($owner, ['title' => 'T', 'body' => 'B', 'to' => 'me'])
            ->assertOk()
            ->assertJson(['sent' => 1, 'failed' => 0]);

        $this->assertSame(['https://push.test/owner-phone'], array_column($this->transport->queued, 'endpoint'));
    }

    public function test_a_list_of_ids_targets_those_members(): void
    {
        [$owner, $partner] = $this->household();

        $this->push($owner, ['title' => 'T', 'body' => 'B', 'to' => [$partner->id]])
            ->assertOk()
            ->assertJson(['sent' => 2, 'failed' => 0]);

        $this->assertEqualsCanonicalizing(
            ['https://push.test/partner-phone', 'https://push.test/partner-ipad'],
            array_column($this->transport->queued, 'endpoint'),
        );
    }

    public function test_an_id_outside_the_household_is_refused_and_nothing_is_sent(): void
    {
        [$owner] = $this->household();
        $stranger = User::factory()->create();
        $stranger->updatePushSubscription('https://push.test/stranger', 'key-9', 'auth-9');

        $this->push($owner, ['title' => 'T', 'body' => 'B', 'to' => [$stranger->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->assertSame([], $this->transport->queued);
    }

    public function test_failed_and_expired_devices_are_counted_and_expired_ones_pruned(): void
    {
        [$owner, $partner] = $this->household();
        $this->transport->outcomes['https://push.test/partner-ipad'] = 410;
        $this->transport->outcomes['https://push.test/owner-phone'] = 500;

        $this->push($owner, ['title' => 'T', 'body' => 'B', 'to' => 'both'])
            ->assertOk()
            ->assertJson(['sent' => 1, 'failed' => 2])
            ->assertJsonPath('message', 'Sent to 1 device. 2 devices failed.');

        $this->assertSame(1, $partner->pushSubscriptions()->count());
        $this->assertSame(1, $owner->pushSubscriptions()->count());
    }

    public function test_a_member_with_no_device_is_skipped_not_failed(): void
    {
        [$owner, $partner] = $this->household();
        $partner->pushSubscriptions()->delete();

        $this->push($owner, ['title' => 'T', 'body' => 'B', 'to' => 'both'])
            ->assertOk()
            ->assertJson(['sent' => 1, 'failed' => 0, 'skipped' => [$partner->id]]);
    }

    public function test_a_user_with_no_household_is_a_household_of_one(): void
    {
        $solo = User::factory()->create();
        $solo->regeneratePhoneToken();
        $solo->updatePushSubscription('https://push.test/solo', 'key-5', 'auth-5');
        $other = User::factory()->create();
        $other->updatePushSubscription('https://push.test/other', 'key-6', 'auth-6');

        $this->push($solo, ['title' => 'T', 'body' => 'B', 'to' => 'both'])
            ->assertOk()
            ->assertJson(['sent' => 1]);

        $this->assertSame(['https://push.test/solo'], array_column($this->transport->queued, 'endpoint'));
    }

    public function test_title_body_and_to_are_required(): void
    {
        [$owner] = $this->household();

        $this->push($owner, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'body', 'to']);

        $this->push($owner, ['title' => 'T', 'body' => 'B', 'to' => 'everyone'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->push($owner, ['title' => 'T', 'body' => 'B', 'to' => 'both', 'url' => 'javascript:alert(1)'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->assertSame([], $this->transport->queued);
    }

    public function test_it_answers_503_when_vapid_is_not_configured(): void
    {
        [$owner] = $this->household();
        config()->set('webpush.vapid.private_key', null);

        $this->push($owner, ['title' => 'T', 'body' => 'B', 'to' => 'both'])
            ->assertStatus(503)
            ->assertJson(['ok' => false]);
    }

    // ---- Token (HUB STANDARDS §2a) -------------------------------------

    public function test_a_bearer_token_is_accepted(): void
    {
        [$owner] = $this->household();

        $this->withHeader('Authorization', 'Bearer '.$owner->phone_token)
            ->postJson(route('api.push.send'), ['title' => 'T', 'body' => 'B', 'to' => 'me'])
            ->assertOk();
    }

    public function test_the_token_is_trimmed(): void
    {
        [$owner] = $this->household();

        $this->withHeader('X-Api-Token', $owner->phone_token."\n")
            ->postJson(route('api.push.send'), ['title' => 'T', 'body' => 'B', 'to' => 'me'])
            ->assertOk();
    }

    public function test_a_missing_token_is_a_401_that_says_missing(): void
    {
        $this->postJson(route('api.push.send'), ['title' => 'T', 'body' => 'B', 'to' => 'both'])
            ->assertUnauthorized()
            ->assertJson(['ok' => false])
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'missing'));
    }

    public function test_a_wrong_token_is_a_401_that_says_not_valid(): void
    {
        $this->household();

        $this->withHeader('X-Api-Token', 'not-a-real-token')
            ->postJson(route('api.push.send'), ['title' => 'T', 'body' => 'B', 'to' => 'both'])
            ->assertUnauthorized()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'not valid'));

        $this->assertSame([], $this->transport->queued);
    }

    public function test_the_old_shortcut_header_is_not_read_here(): void
    {
        [$owner] = $this->household();

        $this->withHeader('X-Shortcut-Token', (string) $owner->phone_token)
            ->postJson(route('api.push.send'), ['title' => 'T', 'body' => 'B', 'to' => 'both'])
            ->assertUnauthorized();
    }

    public function test_a_query_token_does_not_authorise_a_post(): void
    {
        [$owner] = $this->household();

        $this->postJson(route('api.push.send', ['token' => $owner->phone_token]), ['title' => 'T', 'body' => 'B', 'to' => 'both'])
            ->assertUnauthorized();
    }

    public function test_sends_are_rate_limited(): void
    {
        [$owner] = $this->household();

        for ($i = 0; $i < 30; $i++) {
            $this->push($owner, ['title' => 'T', 'body' => 'B', 'to' => 'me'])->assertOk();
        }

        $this->push($owner, ['title' => 'T', 'body' => 'B', 'to' => 'me'])->assertStatus(429);
    }

    // ---- Recipients ----------------------------------------------------

    public function test_recipients_lists_the_household_with_device_counts(): void
    {
        [$owner, $partner] = $this->household();

        $this->withHeader('X-Api-Token', (string) $owner->phone_token)
            ->getJson(route('api.push.recipients'))
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'recipients' => [
                    ['id' => $owner->id, 'name' => 'Keith', 'me' => true, 'devices' => 1],
                    ['id' => $partner->id, 'name' => 'Emily', 'me' => false, 'devices' => 2],
                ],
            ]);
    }

    public function test_recipients_accepts_a_query_token_and_refuses_none(): void
    {
        [$owner] = $this->household();

        $this->getJson(route('api.push.recipients', ['token' => $owner->phone_token]))->assertOk();
        $this->getJson(route('api.push.recipients'))->assertUnauthorized();
    }
}
