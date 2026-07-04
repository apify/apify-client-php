<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use Apify\Client\Options\MetamorphOptions;
use Apify\Client\Options\RequestQueueClientOptions;
use Apify\Client\Options\RunChargeOptions;
use Apify\Client\Options\RunResurrectOptions;
use PHPUnit\Framework\TestCase;

/**
 * Offline request-shape tests for mutating/convenience endpoints that are risky to exercise live on
 * the shared account. Each asserts the HTTP method, path, query and body the client actually sends.
 */
final class RequestShapeTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    public function testRunChargeSendsBodyAndIdempotencyKey(): void
    {
        $transport = (new MockTransport())->queueResponse(200, '');
        $this->client($transport)->run('run1')->charge(new RunChargeOptions(eventName: 'result', count: 3));

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/actor-runs/run1/charge', (string) $request->getUri());
        self::assertNotSame('', $request->getHeaderLine('idempotency-key'));
        self::assertSame(['eventName' => 'result', 'count' => 3], Json::decode((string) $request->getBody()));
    }

    public function testRunChargeUsesProvidedIdempotencyKey(): void
    {
        $transport = (new MockTransport())->queueResponse(200, '');
        $this->client($transport)->run('run1')->charge(new RunChargeOptions(eventName: 'e', idempotencyKey: 'fixed-key'));
        self::assertSame('fixed-key', $transport->lastRequest()->getHeaderLine('idempotency-key'));
    }

    public function testMetamorphSendsTargetActorIdAndInput(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'r']]));
        $this->client($transport)->run('run1')->metamorph('apify/other', ['x' => 1], new MetamorphOptions(build: 'latest'));

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->getMethod());
        $uri = (string) $request->getUri();
        self::assertStringContainsString('/actor-runs/run1/metamorph', $uri);
        self::assertStringContainsString('targetActorId=apify%2Fother', $uri);
        self::assertStringContainsString('build=latest', $uri);
        self::assertSame(['x' => 1], Json::decode((string) $request->getBody()));
    }

    public function testResurrectSendsOptions(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'r']]));
        $this->client($transport)->run('run1')->resurrect(new RunResurrectOptions(build: 'beta', memoryMbytes: 1024));

        $uri = (string) $transport->lastRequest()->getUri();
        self::assertStringContainsString('/actor-runs/run1/resurrect', $uri);
        self::assertStringContainsString('build=beta', $uri);
        self::assertStringContainsString('memory=1024', $uri);
    }

    public function testRebootPostsToRebootPath(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'r']]));
        $this->client($transport)->run('run1')->reboot();

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/actor-runs/run1/reboot', (string) $request->getUri());
    }

    public function testAbortSendsGracefullyFlag(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'r']]));
        $this->client($transport)->run('run1')->abort(true);
        self::assertStringContainsString('gracefully=1', (string) $transport->lastRequest()->getUri());
    }

    public function testDefaultBuildFetchesBuildsDefault(): void
    {
        // Ample per-request timeout so the server-side wait clamp leaves waitForFinish=10 intact.
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'build1']]));
        $client = new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 60, httpClient: $transport);
        $client->actor('me~a')->defaultBuild(10);

        $request = $transport->lastRequest();
        self::assertSame('GET', $request->getMethod());
        $uri = (string) $request->getUri();
        self::assertStringContainsString('/actors/me~a/builds/default', $uri);
        self::assertStringContainsString('waitForFinish=10', $uri);
    }

    public function testDefaultBuildClampsWaitToPerRequestTimeout(): void
    {
        // With a small per-request timeout, the server-side wait is clamped down so the server never
        // holds the connection past the client's socket timeout (consistent with run/build get()).
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'build1']]));
        $client = new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
        $client->actor('me~a')->defaultBuild(120);

        self::assertStringContainsString('waitForFinish=0', (string) $transport->lastRequest()->getUri());
    }

    public function testRequestQueueOptionsApplyClientKey(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['items' => []]]));
        $this->client($transport)
            ->requestQueue('q1', new RequestQueueClientOptions(clientKey: 'ck-123'))
            ->listHead(5);

        self::assertStringContainsString('clientKey=ck-123', (string) $transport->lastRequest()->getUri());
    }

    public function testRequestQueueOptionsApplyTimeout(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['items' => []]]));
        $this->client($transport)
            ->requestQueue('q1', new RequestQueueClientOptions(timeoutSecs: 2.0))
            ->listHead(5);

        // The per-queue timeout must be threaded down to the transport (first attempt uses it directly).
        self::assertSame(2.0, $transport->timeouts[0]);
    }

    public function testUpdateLimitsPutsToMeLimits(): void
    {
        $transport = (new MockTransport())->queueResponse(200, '');
        $this->client($transport)->me()->updateLimits(['maxMonthlyUsageUsd' => 100]);

        $request = $transport->lastRequest();
        self::assertSame('PUT', $request->getMethod());
        self::assertStringContainsString('/users/me/limits', (string) $request->getUri());
        self::assertSame(['maxMonthlyUsageUsd' => 100], Json::decode((string) $request->getBody()));
    }
}
