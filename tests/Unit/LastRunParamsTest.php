<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use Apify\Client\Options\DownloadItemsFormat;
use Apify\Client\Options\LastRunOptions;
use PHPUnit\Framework\TestCase;

/**
 * Offline tests proving that the {@code status}/{@code origin} filters pinned by a last-run accessor
 * are inherited by the run's nested storage and log accessors, so every request resolves the same
 * (correct) run. This mirrors the reference JS client, where sub-resource clients inherit the
 * parent's params. Without this, e.g. {@code actor.lastRun(SUCCEEDED).dataset().listItems()} would
 * silently read the default dataset of the wrong run.
 */
final class LastRunParamsTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    private function lastRun(MockTransport $transport): \Apify\Client\Resource\RunClient
    {
        return $this->client($transport)
            ->actor('me~a')
            ->lastRun(new LastRunOptions(status: 'SUCCEEDED', origin: 'API'));
    }

    public function testNestedDatasetListItemsInheritsStatusAndOrigin(): void
    {
        $transport = (new MockTransport())->queueResponse(200, '[]');
        $this->lastRun($transport)->dataset()->listItems();

        $uri = (string) $transport->lastRequest()->getUri();
        self::assertStringContainsString('/actors/me~a/runs/last/dataset/items', $uri);
        self::assertStringContainsString('status=SUCCEEDED', $uri);
        self::assertStringContainsString('origin=API', $uri);
    }

    public function testNestedDatasetDownloadItemsInheritsStatusAndOrigin(): void
    {
        $transport = (new MockTransport())->queueResponse(200, '[]');
        $this->lastRun($transport)->dataset()->downloadItems(DownloadItemsFormat::CSV);

        $uri = (string) $transport->lastRequest()->getUri();
        self::assertStringContainsString('/actors/me~a/runs/last/dataset/items', $uri);
        self::assertStringContainsString('format=csv', $uri);
        self::assertStringContainsString('status=SUCCEEDED', $uri);
        self::assertStringContainsString('origin=API', $uri);
    }

    public function testNestedDatasetPushItemsInheritsStatusAndOrigin(): void
    {
        $transport = (new MockTransport())->queueResponse(200, '');
        $this->lastRun($transport)->dataset()->pushItems([['a' => 1]]);

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->getMethod());
        $uri = (string) $request->getUri();
        self::assertStringContainsString('/actors/me~a/runs/last/dataset/items', $uri);
        self::assertStringContainsString('status=SUCCEEDED', $uri);
        self::assertStringContainsString('origin=API', $uri);
        self::assertSame([['a' => 1]], Json::decode((string) $request->getBody()));
    }

    public function testNestedKeyValueStoreListKeysInheritsStatusAndOrigin(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['items' => []]]));
        $this->lastRun($transport)->keyValueStore()->listKeys();

        $uri = (string) $transport->lastRequest()->getUri();
        self::assertStringContainsString('/actors/me~a/runs/last/key-value-store/keys', $uri);
        self::assertStringContainsString('status=SUCCEEDED', $uri);
        self::assertStringContainsString('origin=API', $uri);
    }

    public function testNestedKeyValueStoreGetRecordInheritsStatusAndOrigin(): void
    {
        $transport = (new MockTransport())->queueResponse(200, 'hello');
        $this->lastRun($transport)->keyValueStore()->getRecord('my-key');

        $uri = (string) $transport->lastRequest()->getUri();
        self::assertStringContainsString('/actors/me~a/runs/last/key-value-store/records/my-key', $uri);
        self::assertStringContainsString('status=SUCCEEDED', $uri);
        self::assertStringContainsString('origin=API', $uri);
    }

    public function testNestedRequestQueueListHeadInheritsStatusAndOrigin(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['items' => []]]));
        $this->lastRun($transport)->requestQueue()->listHead();

        $uri = (string) $transport->lastRequest()->getUri();
        self::assertStringContainsString('/actors/me~a/runs/last/request-queue/head', $uri);
        self::assertStringContainsString('status=SUCCEEDED', $uri);
        self::assertStringContainsString('origin=API', $uri);
    }

    public function testNestedLogGetInheritsStatusAndOrigin(): void
    {
        $transport = (new MockTransport())->queueResponse(200, 'log line');
        $this->lastRun($transport)->log()->get();

        $uri = (string) $transport->lastRequest()->getUri();
        self::assertStringContainsString('/actors/me~a/runs/last/log', $uri);
        self::assertStringContainsString('status=SUCCEEDED', $uri);
        self::assertStringContainsString('origin=API', $uri);
    }

    public function testNestedStorageWithoutLastRunFilterSendsNoStatusOrigin(): void
    {
        // A plain run (not a last-run accessor) pins no filters, so nested requests carry none.
        $transport = (new MockTransport())->queueResponse(200, '[]');
        $this->client($transport)->run('run1')->dataset()->listItems();

        $uri = (string) $transport->lastRequest()->getUri();
        self::assertStringContainsString('/actor-runs/run1/dataset/items', $uri);
        self::assertStringNotContainsString('status=', $uri);
        self::assertStringNotContainsString('origin=', $uri);
    }
}
