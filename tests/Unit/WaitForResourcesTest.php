<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Internal\Json;
use Apify\Client\Options\ActorStartOptions;
use Apify\Client\Options\TaskStartOptions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Hermetic tests for the {@code waitForResources} option of {@see \Apify\Client\Resource\ActorClient}
 * and {@see \Apify\Client\Resource\TaskClient}'s {@code start()}/{@code call()}, driven by a scripted
 * transport. Mirrors the reference client's {@code startWaitingForResources} behavior.
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

    public function testActorStartRetriesOnResourceLimitUntilItSucceeds(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(402, self::rejection('actor-memory-limit-exceeded'))
            ->queueResponse(402, self::rejection('concurrent-runs-limit-exceeded'))
            ->queueResponse(201, self::runBody());

        $run = $this->client($transport)->actor('a1')->start(null, new ActorStartOptions(waitForResources: true));

        self::assertSame('run1', $run->getId());
        self::assertSame(3, $transport->callCount());
    }

    public function testTaskStartRetriesOnResourceLimitUntilItSucceeds(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(402, self::rejection('actor-memory-limit-exceeded'))
            ->queueResponse(201, self::runBody());

        $run = $this->client($transport)->task('t1')->start(null, new TaskStartOptions(waitForResources: true));

        self::assertSame('run1', $run->getId());
        self::assertSame(2, $transport->callCount());
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

    public function testNumberOfSecondsBoundsTheRetryingAndThrowsTheLastRejection(): void
    {
        $transport = new MockTransport();
        for ($i = 0; $i < 5; $i++) {
            $transport->queueResponse(402, self::rejection('actor-memory-limit-exceeded'));
        }

        $start = microtime(true);
        try {
            $this->client($transport)->actor('a1')->start(null, new ActorStartOptions(waitForResources: 1));
            self::fail('expected an ApifyApiException');
        } catch (ApifyApiException $e) {
            self::assertSame('actor-memory-limit-exceeded', $e->getType());
        }
        $elapsedSecs = microtime(true) - $start;

        // Attempts 10 seconds apart with only a 1-second budget mean a single retry at most, well
        // under the 10s cooldown — asserting this (rather than an exact attempt count) keeps the test
        // robust to scheduling jitter around the 1-second bound.
        self::assertLessThan(5.0, $elapsedSecs);
        self::assertLessThanOrEqual(3, $transport->callCount());
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
