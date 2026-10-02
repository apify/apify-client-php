<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use PHPUnit\Framework\TestCase;

/**
 * Covers the timeout-tier configuration (matches the reference client's `#1046`): every tier defaults
 * to the client's single legacy {@code timeoutSecs} unless explicitly configured, so a client built
 * without the new options sends every call with exactly the timeout it always has (no breaking
 * behavior change from introducing tiers); a per-call override replaces the method's tier for that
 * call alone; and {@code timeoutMaxSecs} caps both.
 */
final class TimeoutTiersTest extends TestCase
{
    public function testEveryTierDefaultsToTheLegacyTimeoutSecsWhenUnconfigured(): void
    {
        // No timeoutShortSecs/timeoutMediumSecs/timeoutLongSecs/timeoutMaxSecs given: every call must
        // use exactly $timeoutSecs, byte-for-byte the pre-tiers behavior.
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'a1']]));
        $client = new ApifyClient(token: 't', timeoutSecs: 123, httpClient: $transport);

        $client->actor('a1')->get();

        self::assertSame([123.0], $transport->timeouts);
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
