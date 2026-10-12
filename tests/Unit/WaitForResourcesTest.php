<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\WaitForResources;
use Apify\Client\Options\ActorStartOptions;
use Apify\Client\Options\TaskStartOptions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the {@code waitForResources} option of {@see \Apify\Client\Resource\ActorClient} and
 * {@see \Apify\Client\Resource\TaskClient}'s {@code start()}/{@code call()}. Mirrors the reference
 * client's {@code startWaitingForResources} behavior.
 *
 * The retry/backoff mechanism itself is exercised directly against {@see WaitForResources::start()}
 * with a near-zero {@code $cooldownSecs} override (a parameter that exists solely for this), so the
 * suite does not sleep through real 10-second cooldowns. That {@code $cooldownSecs} is never passed by
 * {@see \Apify\Client\Resource\ActorClient}/{@see \Apify\Client\Resource\TaskClient} themselves, so the
 * real cooldown is exercised only by the integration suite.
 */
final class WaitForResourcesTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    private static function rejection(string $type): string
    {
        return Json::encode(['error' => ['type' => $type, 'message' => "rejected: $type"]]);
    }

    private static function runBody(string $status = 'READY'): string
    {
        return Json::encode(['data' => ['id' => 'run1', 'status' => $status]]);
    }

    private static function rejectionError(string $type): ApifyApiException
    {
        return new ApifyApiException(402, $type, "rejected: $type", 1, 'POST', '/v2/actor-runs');
    }

    public function testRetriesEveryCooldownUntilItSucceeds(): void
    {
        $attempts = 0;
        $start = static function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw self::rejectionError($attempts === 1 ? 'actor-memory-limit-exceeded' : 'concurrent-runs-limit-exceeded');
            }
            return 'started';
        };

        $result = WaitForResources::start($start, true, cooldownSecs: 0.001);

        self::assertSame('started', $result);
        self::assertSame(3, $attempts);
    }

    public function testNumberOfSecondsBoundsTheRetryingAndThrowsTheLastRejection(): void
    {
        $attempts = 0;
        $start = static function () use (&$attempts) {
            $attempts++;
            throw self::rejectionError('actor-memory-limit-exceeded');
        };

        try {
            WaitForResources::start($start, 1, cooldownSecs: 0.001);
            self::fail('expected an ApifyApiException');
        } catch (ApifyApiException $e) {
            self::assertSame('actor-memory-limit-exceeded', $e->getType());
        }
        // Roughly 1 / 0.001 attempts before the 1-second budget runs out; a wide range keeps this
        // robust to scheduling jitter while still proving it bounds the retrying rather than retrying
        // forever. $waitForResources only accepts a whole number of seconds, so 1 is the smallest
        // nonzero budget this can exercise without hitting the (already separately tested) "0 makes a
        // single attempt" case.
        self::assertGreaterThan(10, $attempts);
        self::assertLessThan(10000, $attempts);
    }

    public function testActorStartAndTaskStartWireTheOptionIntoWaitForResources(): void
    {
        // Succeeding on the very first attempt needs no cooldown at all, so this confirms
        // ActorClient/TaskClient actually construct the WaitForResources::start() call (rather than,
        // say, silently dropping the option) without the suite paying for even one real sleep; the
        // retry/cooldown mechanism itself is exercised above, directly against WaitForResources.
        $actorTransport = (new MockTransport())->queueResponse(201, self::runBody());
        $run = $this->client($actorTransport)->actor('a1')->start(null, new ActorStartOptions(waitForResources: true));
        self::assertSame('run1', $run->getId());

        $taskTransport = (new MockTransport())->queueResponse(201, self::runBody());
        $run = $this->client($taskTransport)->task('t1')->start(null, new TaskStartOptions(waitForResources: true));
        self::assertSame('run1', $run->getId());
    }

    public function testThrowsFirstRejectionWithoutTheOption(): void
    {
        $transport = (new MockTransport())->queueResponse(402, self::rejection('actor-memory-limit-exceeded'));

        try {
            $this->client($transport)->actor('a1')->start();
            self::fail('expected an ApifyApiException');
        } catch (ApifyApiException $e) {
            self::assertSame('actor-memory-limit-exceeded', $e->getType());
        }
        self::assertSame(1, $transport->callCount());
    }

    public function testFalseBehavesLikeNoOption(): void
    {
        $transport = (new MockTransport())->queueResponse(402, self::rejection('concurrent-runs-limit-exceeded'));

        $this->expectException(ApifyApiException::class);
        $this->client($transport)->actor('a1')->start(null, new ActorStartOptions(waitForResources: false));
    }

    public function testThrowsAnyOtherErrorRightAway(): void
    {
        $transport = (new MockTransport())->queueResponse(400, self::rejection('invalid-input'));

        try {
            $this->client($transport)->actor('a1')->start(null, new ActorStartOptions(waitForResources: true));
            self::fail('expected an ApifyApiException');
        } catch (ApifyApiException $e) {
            self::assertSame('invalid-input', $e->getType());
        }
        self::assertSame(1, $transport->callCount());
    }

    public function testZeroSecondsMakesASingleAttempt(): void
    {
        $transport = (new MockTransport())->queueResponse(402, self::rejection('actor-memory-limit-exceeded'));

        $this->expectException(ApifyApiException::class);
        $this->client($transport)->actor('a1')->start(null, new ActorStartOptions(waitForResources: 0));
    }

    public function testRejectsANegativeNumberOfSeconds(): void
    {
        $transport = new MockTransport();

        try {
            $this->client($transport)->actor('a1')->start(null, new ActorStartOptions(waitForResources: -1));
            self::fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            // No request should have been attempted: the option is invalid before any start is tried.
            self::assertSame(0, $transport->callCount());
        }
    }
}
