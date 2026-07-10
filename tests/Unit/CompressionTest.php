<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\Internal\Compression;
use PHPUnit\Framework\TestCase;

final class CompressionTest extends TestCase
{
    /** True when at least one compression codec is available in this PHP build. */
    private static function hasCodec(): bool
    {
        return function_exists('brotli_compress') || function_exists('gzencode');
    }

    public function testSmallBodyIsNotCompressed(): void
    {
        $body = str_repeat('a', Compression::MIN_COMPRESS_BYTES - 1);
        self::assertNull(Compression::maybeCompress($body));
    }

    public function testBodyAtThresholdIsCompressed(): void
    {
        if (!self::hasCodec()) {
            self::markTestSkipped('no compression codec available in this PHP build');
        }
        // A body of exactly MIN_COMPRESS_BYTES is compressed (the below-threshold case is covered by
        // testSmallBodyIsNotCompressed).
        $result = Compression::maybeCompress(str_repeat('a', Compression::MIN_COMPRESS_BYTES));
        self::assertNotNull($result);
        [$encoding, $data] = $result;
        self::assertContains($encoding, ['br', 'gzip']);
        self::assertNotSame('', $data);
    }

    public function testCompressedBodyRoundTrips(): void
    {
        if (!self::hasCodec()) {
            self::markTestSkipped('no compression codec available in this PHP build');
        }
        $original = json_encode(['items' => array_fill(0, 500, ['field' => 'value-with-some-length'])]);
        self::assertIsString($original);

        $result = Compression::maybeCompress($original);
        self::assertNotNull($result);
        [$encoding, $data] = $result;

        // Highly repetitive JSON must shrink.
        self::assertLessThan(strlen($original), strlen($data));

        $decoded = self::decode($encoding, $data);
        self::assertSame($original, $decoded);
    }

    public function testPrefersBrotliWhenAvailableElseGzip(): void
    {
        if (!self::hasCodec()) {
            self::markTestSkipped('no compression codec available in this PHP build');
        }
        $result = Compression::maybeCompress(str_repeat('payload-', 500));
        self::assertNotNull($result);
        [$encoding] = $result;

        $expected = function_exists('brotli_compress') ? 'br' : 'gzip';
        self::assertSame($expected, $encoding);
    }

    public function testGzipPathWhenBrotliUnavailable(): void
    {
        if (!function_exists('gzencode')) {
            self::markTestSkipped('zlib (gzencode) not available in this PHP build');
        }
        // Deterministically exercise the gzip fallback (brotli encoder absent) without depending on
        // the host lacking the PECL brotli extension.
        $original = str_repeat('payload-', 500);
        $result = Compression::compressWith($original, null, static fn (string $b) => gzencode($b));
        self::assertNotNull($result);
        [$encoding, $data] = $result;
        self::assertSame('gzip', $encoding);
        self::assertLessThan(strlen($original), strlen($data));
        self::assertSame($original, gzdecode($data));
    }

    public function testBrotliPathIsPreferredWhenAvailable(): void
    {
        // Deterministically exercise the brotli path (and its preference over gzip) without depending
        // on the host having the PECL brotli extension: inject a stand-in brotli encoder and assert it
        // is chosen and its output used, while a real gzip encoder is also available.
        $original = str_repeat('payload-', 500);
        $marker = 'BR:' . $original;
        $result = Compression::compressWith(
            $original,
            static fn (string $b) => 'BR:' . $b,
            static fn (string $b) => gzencode($b),
        );
        self::assertNotNull($result);
        [$encoding, $data] = $result;
        self::assertSame('br', $encoding);
        self::assertSame($marker, $data);
    }

    public function testRealBrotliRoundTripsWhenExtensionPresent(): void
    {
        if (!function_exists('brotli_compress')) {
            self::markTestSkipped('PECL brotli extension not loaded');
        }
        // When the real extension is present, verify the actual brotli codec produces decodable bytes.
        $original = str_repeat('payload-', 500);
        $result = Compression::maybeCompress($original);
        self::assertNotNull($result);
        [$encoding, $data] = $result;
        self::assertSame('br', $encoding);
        self::assertLessThan(strlen($original), strlen($data));
        self::assertSame($original, self::decode('br', $data));
    }

    public function testFallsBackToGzipWhenBrotliEncoderFails(): void
    {
        if (!function_exists('gzencode')) {
            self::markTestSkipped('zlib (gzencode) not available in this PHP build');
        }
        // A brotli encoder that fails (returns a non-string) must not abort compression: gzip is used.
        $original = str_repeat('payload-', 500);
        $result = Compression::compressWith(
            $original,
            static fn (string $b) => false,
            static fn (string $b) => gzencode($b),
        );
        self::assertNotNull($result);
        [$encoding, $data] = $result;
        self::assertSame('gzip', $encoding);
        self::assertSame($original, gzdecode($data));
    }

    public function testReturnsNullWhenNoCodecAvailable(): void
    {
        $original = str_repeat('payload-', 500);
        self::assertNull(Compression::compressWith($original, null, null));
    }

    public function testSmallBodyIsNotCompressedEvenWithCodecs(): void
    {
        // The size gate applies before codec selection, so a below-threshold body is never compressed.
        $small = str_repeat('a', Compression::MIN_COMPRESS_BYTES - 1);
        self::assertNull(Compression::compressWith(
            $small,
            static fn (string $b) => 'BR:' . $b,
            static fn (string $b) => gzencode($b),
        ));
    }

    private static function decode(string $encoding, string $data): string
    {
        if ($encoding === 'br') {
            self::assertTrue(function_exists('brotli_uncompress'), 'brotli extension needed to decode');
            // Called indirectly: the symbol only exists when the PECL brotli extension is loaded,
            // so a direct call would be an unresolved reference for static analysis.
            $brotliUncompress = 'brotli_uncompress';
            $decoded = $brotliUncompress($data);
        } else {
            $decoded = gzdecode($data);
        }
        self::assertIsString($decoded);
        return $decoded;
    }
}
