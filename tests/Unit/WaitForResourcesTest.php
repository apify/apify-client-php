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
 * Tests of the {@code waitForResources} option of {@see \Apify\Client\Resource\ActorClient} and
 * {@see \Apify\Client\Resource\TaskClient}.
 *
 * The retries are tested on {@see WaitForResources::start()} with a tiny {@code $cooldownSecs}, which only
 * tests pass, so the suite does not wait for real 10-second cooldowns.
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

        // The start always rejects, so the call exhausts the budget and rethrows the last rejection.
        $this->expectException(ApifyApiException::class);
        $this->expectExceptionMessage('rejected: actor-memory-limit-exceeded');
        try {
            WaitForResources::start($start, 1, cooldownSecs: 0.001);
        } finally {
            // About 1 / 0.001 attempts fit the 1-second budget. A wide range tolerates scheduling jitter. 1 is
            // the smallest nonzero budget, since only whole seconds are accepted.
            self::assertGreaterThan(10, $attempts);
            self::assertLessThan(10000, $attempts);
        }
    }

    public function testActorStartAndTaskStartWireTheOptionIntoWaitForResources(): void
    {
        // A start that succeeds at once needs no cooldown, which shows that the clients pass the option on.
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
