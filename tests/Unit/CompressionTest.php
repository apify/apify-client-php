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
