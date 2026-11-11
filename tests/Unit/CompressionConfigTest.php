<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Http\BrotliHttpCompressor;
use Apify\Client\Http\GzipHttpCompressor;
use Apify\Client\Http\ZstdHttpCompressor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests of the {@code compression} constructor option: algorithm by name, quality through a compressor, and the
 * default when it is omitted.
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

    public function testZstdByNameIsUsedWhenExtensionAvailable(): void
    {
        if (!function_exists('zstd_compress')) {
            self::markTestSkipped('PECL zstd extension not loaded');
        }
        $transport = (new MockTransport())->queueResponse(200, '{"data":{}}');
        $client = new ApifyClient(token: 't', compression: 'zstd', httpClient: $transport);

        $client->keyValueStore('s1')->setRecordJson('k', $this->largeBody());

        self::assertSame('zstd', $transport->lastRequest()->getHeaderLine('Content-Encoding'));
    }

    /** @return array<string,array{string,string}> */
    public static function codecsWithAnExtension(): array
    {
        return [
            'brotli' => ['brotli', 'brotli_compress'],
            'zstd' => ['zstd', 'zstd_compress'],
        ];
    }

    #[DataProvider('codecsWithAnExtension')]
    public function testChoosingACodecWithoutItsExtensionFailsAtConstruction(string $name, string $function): void
    {
        if (function_exists($function)) {
            self::markTestSkipped("the $name extension is loaded");
        }
        $this->expectException(RuntimeException::class);
        new ApifyClient(token: 't', compression: $name, httpClient: new MockTransport());
    }

    public function testZstdCompressorRoundTrips(): void
    {
        if (!function_exists('zstd_uncompress')) {
            self::markTestSkipped('PECL zstd extension not loaded');
        }
        $compressor = new ZstdHttpCompressor();
        self::assertSame('zstd', $compressor->contentEncoding());
        $zstdUncompress = 'zstd_uncompress';
        self::assertSame('payload', $zstdUncompress((string) $compressor->compress('payload')));
    }

    /** @return array<string,array{int}> */
    public static function outOfRangeZstdQualities(): array
    {
        return ['below the minimum' => [0], 'above the maximum' => [20]];
    }

    #[DataProvider('outOfRangeZstdQualities')]
    public function testZstdCompressorRejectsOutOfRangeQuality(int $quality): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ZstdHttpCompressor($quality);
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
