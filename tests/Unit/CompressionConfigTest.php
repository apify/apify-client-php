<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Http\BrotliHttpCompressor;
use Apify\Client\Http\GzipHttpCompressor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the configurable {@code compression} constructor option (matches the reference client's
 * `#1065`): choosing {@code 'gzip'}/{@code 'brotli'} by name, a custom quality via an explicit
 * compressor instance, and that the client's default best-effort choice is unchanged when the option
 * is left out.
 */
final class CompressionConfigTest extends TestCase
{
    private const LARGE_BODY_FIELD = 'blob'; // forces the JSON body above the compression threshold

    /** @return array<string,string> */
    private function largeBody(): array
    {
        return [self::LARGE_BODY_FIELD => str_repeat('payload-', 500)];
    }

    public function testGzipByNameForcesGzipEvenWhenBrotliAvailable(): void
    {
        $transport = (new MockTransport())->queueResponse(200, '{"data":{}}');
        $client = new ApifyClient(token: 't', compression: 'gzip', httpClient: $transport);

        $client->keyValueStore('s1')->setRecordJson('k', $this->largeBody());

        $request = $transport->lastRequest();
        self::assertSame('gzip', $request->getHeaderLine('Content-Encoding'));
    }

    public function testBrotliByNameIsUsedWhenExtensionAvailable(): void
    {
        if (!function_exists('brotli_compress')) {
            self::markTestSkipped('PECL brotli extension not loaded');
        }
        $transport = (new MockTransport())->queueResponse(200, '{"data":{}}');
        $client = new ApifyClient(token: 't', compression: 'brotli', httpClient: $transport);

        $client->keyValueStore('s1')->setRecordJson('k', $this->largeBody());

        self::assertSame('br', $transport->lastRequest()->getHeaderLine('Content-Encoding'));
    }

    public function testCustomCompressorInstanceIsUsed(): void
    {
        $transport = (new MockTransport())->queueResponse(200, '{"data":{}}');
        $client = new ApifyClient(token: 't', compression: new GzipHttpCompressor(quality: 1), httpClient: $transport);

        $client->keyValueStore('s1')->setRecordJson('k', $this->largeBody());

        $request = $transport->lastRequest();
        self::assertSame('gzip', $request->getHeaderLine('Content-Encoding'));
        $decoded = gzdecode((string) $request->getBody());
        self::assertIsString($decoded);
        self::assertStringContainsString(self::LARGE_BODY_FIELD, $decoded);
    }

    public function testDefaultBehaviorUnchangedWhenCompressionOptionOmitted(): void
    {
        $transport = (new MockTransport())->queueResponse(200, '{"data":{}}');
        $client = new ApifyClient(token: 't', httpClient: $transport);

        $client->keyValueStore('s1')->setRecordJson('k', $this->largeBody());

        $encoding = $transport->lastRequest()->getHeaderLine('Content-Encoding');
        $expected = function_exists('brotli_compress') ? 'br' : 'gzip';
        self::assertSame($expected, $encoding);
    }

    public function testUnknownCompressionNameThrows(): void
    {
        $this->expectException(RuntimeException::class);
        new ApifyClient(token: 't', compression: 'deflate', httpClient: new MockTransport());
    }

    public function testBrotliCompressorRejectsOutOfRangeQuality(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BrotliHttpCompressor(12);
    }

    public function testGzipCompressorRejectsOutOfRangeQuality(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GzipHttpCompressor(0);
    }

    /** A content type that already carries its own compression is still skipped under a configured compressor. */
    public function testAlreadyCompressedContentTypeSkipsConfiguredCompressorToo(): void
    {
        $transport = (new MockTransport())->queueResponse(200, '');
        $client = new ApifyClient(token: 't', compression: 'gzip', httpClient: $transport);

        $client->keyValueStore('s1')->setRecord('k', str_repeat('x', 5000), 'image/png');

        self::assertSame('', $transport->lastRequest()->getHeaderLine('Content-Encoding'));
    }
}
