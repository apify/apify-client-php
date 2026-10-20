<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Options\SetRecordOptions;
use PHPUnit\Framework\TestCase;

/**
 * Covers the {@code $contentEncoding} parameter of
 * {@see \Apify\Client\Resource\KeyValueStoreClient::setRecord()}, matching the reference client's
 * {@code contentEncoding} option of {@code setRecord()} (apify/apify-client-js#1140): a value that is
 * already compressed is uploaded as is, never compressed a second time.
 */
final class KeyValueStoreContentEncodingTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    public function testContentEncodingHeaderIsSentExactlyAsGiven(): void
    {
        $transport = (new MockTransport())->queueResponse(200, '');
        $gzipped = gzencode('a value that would normally be compressed by the client itself');
        self::assertIsString($gzipped);

        $this->client($transport)->keyValueStore('s1')->setRecord(
            'report.json',
            $gzipped,
            'application/json',
            contentEncoding: 'gzip',
        );

        $request = $transport->lastRequest();
        self::assertSame('gzip', $request->getHeaderLine('Content-Encoding'));
        // The body reaches the wire byte-for-byte: the client's own compression must have backed off
        // (it already does for any caller-supplied Content-Encoding — see HttpClientCore::maybeCompressBody()),
        // so the bytes are not gzipped a second time.
        self::assertSame($gzipped, (string) $request->getBody());
    }

    public function testOmittingContentEncodingStillAllowsAutomaticCompression(): void
    {
        if (!function_exists('gzencode') && !function_exists('brotli_compress')) {
            self::markTestSkipped('no compression codec available in this PHP build');
        }
        $transport = (new MockTransport())->queueResponse(200, '');
        $large = str_repeat('x', 4096);

        $this->client($transport)->keyValueStore('s1')->setRecord('k', $large, 'text/plain');

        $request = $transport->lastRequest();
        self::assertContains($request->getHeaderLine('Content-Encoding'), ['br', 'gzip']);
        self::assertLessThan(strlen($large), strlen((string) $request->getBody()));
    }

    public function testContentEncodingIsForwardedAlongsideWriteOptions(): void
    {
        $transport = (new MockTransport())->queueResponse(200, '');

        $this->client($transport)->keyValueStore('s1')->setRecord(
            'k',
            'raw-bytes',
            'application/octet-stream',
            new SetRecordOptions(doNotRetryTimeouts: true),
            'identity',
        );

        self::assertSame('identity', $transport->lastRequest()->getHeaderLine('Content-Encoding'));
        self::assertSame('raw-bytes', (string) $transport->lastRequest()->getBody());
    }
}
