<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Options\LogOptions;
use PHPUnit\Framework\TestCase;

final class LogClientTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    public function testGetLogByIdReturnsText(): void
    {
        $transport = (new MockTransport())->queueResponse(200, "line1\nline2\n");
        $log = $this->client($transport)->log('run1')->get();

        self::assertSame("line1\nline2\n", $log);
        $request = $transport->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertStringContainsString('/logs/run1', (string) $request->getUri());
    }

    public function testMissingLogReturnsNull(): void
    {
        $body = json_encode(['error' => ['type' => 'record-not-found', 'message' => 'no log']]);
        $transport = (new MockTransport())->queueResponse(404, (string) $body);
        self::assertNull($this->client($transport)->log('missing')->get());
    }

    public function testRunNestedLogGet(): void
    {
        $transport = (new MockTransport())->queueResponse(200, 'run log');
        $log = $this->client($transport)->run('run1')->log()->get(new LogOptions(raw: true));

        self::assertSame('run log', $log);
        $uri = (string) $transport->lastRequest()->getUri();
        self::assertStringContainsString('/actor-runs/run1/log', $uri);
        self::assertStringContainsString('raw=1', $uri);
    }

    public function testStreamedLogUsesStreamQueryAndReturnsReadableStream(): void
    {
        $transport = (new MockTransport())->queueResponse(200, 'streamed log body');
        $stream = $this->client($transport)->run('run1')->getStreamedLog();

        $uri = (string) $transport->lastRequest()->getUri();
        self::assertStringContainsString('/actor-runs/run1/log', $uri);
        self::assertStringContainsString('stream=1', $uri);
        self::assertStringContainsString('raw=1', $uri);
        self::assertSame('streamed log body', (string) $stream);
    }
}
