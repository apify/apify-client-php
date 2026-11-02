<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use Apify\Client\Options\ActorBuildOptions;
use Apify\Client\Options\ActorStartOptions;
use Apify\Client\Options\RequestQueueClientOptions;
use Apify\Client\Options\TaskStartOptions;
use PHPUnit\Framework\TestCase;

/**
 * Covers the timeout-tier configuration (matches the reference client's `#1046`): the {@code short} and
 * {@code medium} tiers default to 5s and 30s, the {@code long} tier and {@code timeoutMaxSecs} to the
 * client's overall {@code timeoutSecs}; a per-call override replaces the method's tier for that call
 * alone; {@code timeoutMaxSecs} caps both; and a {@code waitForFinish} request gets the API's hold on
 * top of its tier.
 */
final class TimeoutTiersTest extends TestCase
{
    public function testTiersUseTheReferenceDefaultsWhenUnconfigured(): void
    {
        // No timeout*Secs tier option given: short 5s, medium 30s, long and the cap the overall timeoutSecs.
        $transport = (new MockTransport())
            ->queueResponse(200, Json::encode(['data' => ['id' => 'a1']]))
            ->queueResponse(200, Json::encode(['data' => ['items' => []]]))
            ->queueResponse(200, Json::encode(['data' => ['id' => 'a1']]));
        $client = new ApifyClient(token: 't', timeoutSecs: 123, httpClient: $transport);

        $client->actor('a1')->get();
        $client->actors()->list();
        $client->actor('a1')->get(timeoutSecs: 'long');

        self::assertSame([5.0, 30.0, 123.0], $transport->timeouts);
    }

    public function testDefaultTimeoutSecsIsTheLongTierAndTheCap(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'a1']]));
        $client = new ApifyClient(token: 't', httpClient: $transport);

        $client->actor('a1')->get(timeoutSecs: 'long');

        self::assertSame([360.0], $transport->timeouts);
    }

    public function testWaitForFinishRequestGetsTheApiHoldOnTopOfItsTier(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, Json::encode(['data' => ['id' => 'run1', 'status' => 'RUNNING']]))
            ->queueResponse(200, Json::encode(['data' => ['id' => 'run1', 'status' => 'RUNNING']]))
            ->queueResponse(200, Json::encode(['data' => ['id' => 'run1', 'status' => 'RUNNING']]))
            ->queueResponse(200, Json::encode(['data' => ['id' => 'run1', 'status' => 'RUNNING']]));
        $client = new ApifyClient(token: 't', httpClient: $transport);

        $client->run('run1')->get();
        $client->run('run1')->get(30);
        // The API holds a response for at most 60s, however long the parameter asks for.
        $client->run('run1')->get(600);
        // An explicit per-call timeout is used as given.
        $client->run('run1')->get(30, 9);

        self::assertSame([5.0, 35.0, 65.0, 9.0], $transport->timeouts);
    }

    public function testStartAndBuildWaitForFinishOptionExtendTheMediumTier(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, Json::encode(['data' => ['id' => 'run1', 'status' => 'READY']]))
            ->queueResponse(200, Json::encode(['data' => ['id' => 'run1', 'status' => 'READY']]))
            ->queueResponse(200, Json::encode(['data' => ['id' => 'b1', 'status' => 'READY']]));
        $client = new ApifyClient(token: 't', httpClient: $transport);

        $client->actor('a1')->start(null, new ActorStartOptions(waitForFinish: 20));
        $client->task('t1')->start(null, new TaskStartOptions(waitForFinish: 60));
        $client->actor('a1')->build('0.1', new ActorBuildOptions(waitForFinish: 5));

        self::assertSame([50.0, 90.0, 35.0], $transport->timeouts);
    }

    public function testConfiguredShortTierAppliesToGet(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'a1']]));
        $client = new ApifyClient(token: 't', timeoutSecs: 300, timeoutShortSecs: 7, httpClient: $transport);

        $client->actor('a1')->get();

        self::assertSame([7.0], $transport->timeouts);
    }

    public function testPerCallNumericOverrideReplacesTheConfiguredTier(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'a1']]));
        $client = new ApifyClient(token: 't', timeoutSecs: 300, timeoutShortSecs: 7, httpClient: $transport);

        $client->actor('a1')->get(timeoutSecs: 42);

        self::assertSame([42.0], $transport->timeouts);
    }

    public function testPerCallTierNameOverrideSelectsThatTier(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'a1']]));
        $client = new ApifyClient(
            token: 't',
            timeoutSecs: 300,
            timeoutShortSecs: 5,
            timeoutLongSecs: 360,
            timeoutMaxSecs: 360,
            httpClient: $transport,
        );

        $client->actor('a1')->get(timeoutSecs: 'long');

        self::assertSame([360.0], $transport->timeouts);
    }

    public function testTimeoutMaxSecsCapsAPerCallOverride(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'a1']]));
        $client = new ApifyClient(token: 't', timeoutSecs: 300, timeoutMaxSecs: 60, httpClient: $transport);

        $client->actor('a1')->get(timeoutSecs: 999);

        self::assertSame([60.0], $transport->timeouts);
    }

    public function testTimeoutMaxSecsCapsAConfiguredTier(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'a1']]));
        $client = new ApifyClient(
            token: 't',
            timeoutSecs: 300,
            timeoutLongSecs: 500,
            timeoutMaxSecs: 60,
            httpClient: $transport,
        );

        $client->actor('a1')->get(timeoutSecs: 'long');

        self::assertSame([60.0], $transport->timeouts);
    }

    public function testNoTimeoutSendsZeroAsTheRequestTimeout(): void
    {
        // Guzzle (and the cURL option it sets) treats a 0.0 request timeout as "wait indefinitely",
        // so 'noTimeout' must reach the transport as 0.0, not be silently coalesced back to a default.
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'a1']]));
        $client = new ApifyClient(token: 't', timeoutSecs: 300, httpClient: $transport);

        $client->actor('a1')->get(timeoutSecs: 'noTimeout');

        self::assertSame([0.0], $transport->timeouts);
    }

    public function testPerQueueTimeoutCapsEvenAnExplicitNoTimeoutOverride(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['items' => []]]));
        $client = new ApifyClient(token: 't', timeoutSecs: 300, httpClient: $transport);

        $client->requestQueue('q1', new RequestQueueClientOptions(timeoutSecs: 5.0))->get(timeoutSecs: 'noTimeout');

        self::assertSame([5.0], $transport->timeouts);
    }

    public function testPerQueueTimeoutCapsTheResolvedTierButDoesNotOverrideASmallerPerCallValue(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['items' => []]]));
        $client = new ApifyClient(token: 't', timeoutSecs: 300, httpClient: $transport);

        // The per-queue cap (5s) is above the explicit per-call override (2s), so the smaller value wins.
        $client->requestQueue('q1', new RequestQueueClientOptions(timeoutSecs: 5.0))->get(timeoutSecs: 2);

        self::assertSame([2.0], $transport->timeouts);
    }

    public function testUpdateAndDeleteAcceptThePerCallOverrideToo(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, Json::encode(['data' => ['id' => 'a1']]))
            ->queueResponse(200, Json::encode(['data' => []]));
        $client = new ApifyClient(token: 't', timeoutSecs: 300, httpClient: $transport);

        $client->actor('a1')->update(['name' => 'x'], timeoutSecs: 11);
        $client->actor('a1')->delete(timeoutSecs: 22);

        self::assertSame([11.0, 22.0], $transport->timeouts);
    }
}
