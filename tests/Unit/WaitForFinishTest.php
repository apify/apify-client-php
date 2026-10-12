<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Exception\NotFoundException;
use Apify\Client\Internal\Json;
use PHPUnit\Framework\TestCase;

/**
 * Hermetic tests for {@see \Apify\Client\Resource\RunClient::waitForFinish()} /
 * {@see \Apify\Client\Resource\BuildClient::waitForFinish()}'s polling loop, driven by a scripted
 * transport. They exercise the 404-tolerance grace window added alongside the reference client's
 * {@code waitForFinish()} fix: a run/build that 404s right after creation (replica lag) is polled
 * through, but one that keeps 404ing gives up and throws instead of polling for the whole budget.
 */
final class WaitForFinishTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    private static function notFoundBody(): string
    {
        return Json::encode(['error' => ['type' => 'record-not-found', 'message' => 'Run was not found']]);
    }

    private static function runBody(string $status): string
    {
        return Json::encode(['data' => ['id' => 'run1', 'status' => $status]]);
    }

    public function testTogglesThroughTransientNotFoundToTerminalStatus(): void
    {
        // Simulates replica lag right after the run is created: a couple of 404s, then the run
        // becomes visible and eventually finishes. Each 404 must not abort the wait outright, and a
        // successful poll in between must reset the not-found grace window.
        $transport = (new MockTransport())
            ->queueResponse(404, self::notFoundBody())
            ->queueResponse(404, self::notFoundBody())
            ->queueResponse(200, self::runBody('RUNNING'))
            ->queueResponse(404, self::notFoundBody())
            ->queueResponse(200, self::runBody('SUCCEEDED'));

        $run = $this->client($transport)->run('run1')->waitForFinish(30);

        self::assertSame('SUCCEEDED', $run->getStatus());
        self::assertSame(5, $transport->callCount());
    }

    public function testGivesUpAfterNotFoundGraceWindowElapses(): void
    {
        // A run/build that keeps 404ing (e.g. it was never actually created, or was deleted) must not
        // be polled for the whole wait budget: the wait gives up after a few seconds and rethrows the
        // 404 as a NotFoundException instead.
        $transport = new MockTransport();
        for ($i = 0; $i < 30; $i++) {
            $transport->queueResponse(404, self::notFoundBody());
        }

        $start = microtime(true);
        try {
            $this->client($transport)->build('build1')->waitForFinish(60);
            self::fail('expected a NotFoundException');
        } catch (NotFoundException $e) {
            self::assertSame('record-not-found', $e->getType());
        }
        $elapsedSecs = microtime(true) - $start;

        // The grace window is a few seconds; giving up must happen well short of the 60s budget.
        self::assertLessThan(10.0, $elapsedSecs);
        self::assertGreaterThan(1, $transport->callCount());
    }

    public function testNonNotFoundErrorPropagatesImmediately(): void
    {
        // 403 is not server-retried (unlike 5xx/429), so this asserts the polling loop itself gives up
        // on the first non-404 error rather than treating it like a transient not-found.
        $transport = (new MockTransport())->queueResponse(403, Json::encode(['error' => ['type' => 'permission-denied', 'message' => 'no access']]));

        $this->expectException(\Apify\Client\Exception\ForbiddenException::class);
        $this->client($transport)->run('run1')->waitForFinish(30);
    }
}
