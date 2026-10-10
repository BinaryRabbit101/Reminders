<?php

namespace Tests\Support;

use Generator;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;

/**
 * Stands in for minishlink's WebPush inside the real channel: records what
 * would have been sent and answers each endpoint with a canned status
 * (201 unless `outcomes` says otherwise; 404/410 read as expired).
 */
class FakePushTransport extends WebPush
{
    /** @var list<array{endpoint: string, payload: array<string, mixed>}> */
    public array $queued = [];

    /** @var array<string, int> */
    public array $outcomes = [];

    /** @var list<string> */
    private array $pending = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function queueNotification(SubscriptionInterface $subscription, ?string $payload = null, array $options = [], array $auth = []): void
    {
        $this->queued[] = ['endpoint' => $subscription->getEndpoint(), 'payload' => json_decode((string) $payload, true)];
        $this->pending[] = $subscription->getEndpoint();
    }

    public function flush(?int $batchSize = null): Generator
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $endpoint) {
            $status = $this->outcomes[$endpoint] ?? 201;

            yield new MessageSentReport(new Request('POST', $endpoint), new Response($status), $status < 300, $status < 300 ? 'OK' : 'Failed');
        }
    }
}
