<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Internal\Json;
use Apify\Client\Options\DatasetDownloadOptions;
use Apify\Client\Options\DownloadItemsFormat;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see \Apify\Client\Resource\DatasetClient::streamItems()}, this client's equivalent of the
 * reference client's {@code downloadItems(format, { stream: true })} (previously its own
 * {@code streamItems()} — apify/apify-client-js#1139, folded into the option a release later by
 * apify/apify-client-js#1152).
 */
final class DatasetStreamItemsTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    public function testStreamItemsRequestsSameUrlAsDownloadItems(): void
    {
        $transport = (new MockTransport())->queueResponse(200, "url,n\nhttps://a.com,1\n");
        $stream = $this->client($transport)->dataset('ds1')->streamItems(
            DownloadItemsFormat::CSV,
            new DatasetDownloadOptions(bom: true)
        );

        self::assertSame("url,n\nhttps://a.com,1\n", (string) $stream);
        $uri = (string) $transport->lastRequest()->getUri();
        self::assertStringContainsString('/datasets/ds1/items', $uri);
        self::assertStringContainsString('format=csv', $uri);
        self::assertStringContainsString('bom=1', $uri);
    }

    public function testStreamItemsUsesStreamingTransportNotRetrying(): void
    {
        // A server error on a streamed request must surface immediately (streaming bypasses retries),
        // unlike the buffered downloadItems()/get() calls, which retry 5xx responses.
        $transport = (new MockTransport())
            ->queueResponse(500, Json::encode(['error' => ['type' => 'internal-error', 'message' => 'boom']]));

        try {
            $this->client($transport)->dataset('ds1')->streamItems(DownloadItemsFormat::JSON);
            self::fail('expected ApifyApiException');
        } catch (ApifyApiException $e) {
            self::assertSame(500, $e->getStatusCode());
            self::assertSame(1, $transport->callCount());
        }
    }
}
