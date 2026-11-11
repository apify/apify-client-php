<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\Http\HttpCompressorInterface;
use Apify\Client\Internal\Compression;
use PHPUnit\Framework\TestCase;

final class CompressionTest extends TestCase
{
    /** A compressor that encodes with {@code $encode}, or fails when it returns {@code null}. */
    private static function compressor(string $encoding, callable $encode): HttpCompressorInterface
    {
        return new class ($encoding, $encode) implements HttpCompressorInterface {
            /** @var callable(string): ?string */
            private $encode;

            public function __construct(private string $encoding, callable $encode)
            {
                $this->encode = $encode;
            }

            public function contentEncoding(): string
            {
                return $this->encoding;
            }

            public function compress(string $body): ?string
            {
                return ($this->encode)($body);
            }
        };
    }

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
        // The size just below the threshold is covered by testSmallBodyIsNotCompressed.
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
        // Gzip alone, whether or not the host has the brotli extension.
        $original = str_repeat('payload-', 500);
        $result = Compression::compressWith($original, [self::compressor('gzip', static fn (string $b) => gzencode($b) ?: null)]);
        self::assertNotNull($result);
        [$encoding, $data] = $result;
        self::assertSame('gzip', $encoding);
        self::assertLessThan(strlen($original), strlen($data));
        self::assertSame($original, gzdecode($data));
    }

    public function testBrotliPathIsPreferredWhenAvailable(): void
    {
        // A stand-in brotli compressor in front of gzip is chosen, whether or not the host has the extension.
        $original = str_repeat('payload-', 500);
        $marker = 'BR:' . $original;
        $result = Compression::compressWith($original, [
            self::compressor('br', static fn (string $b) => 'BR:' . $b),
            self::compressor('gzip', static fn (string $b) => gzencode($b) ?: null),
        ]);
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
        // A failing brotli compressor does not abort the compression: gzip is used.
        $original = str_repeat('payload-', 500);
        $result = Compression::compressWith($original, [
            self::compressor('br', static fn (string $b) => null),
            self::compressor('gzip', static fn (string $b) => gzencode($b) ?: null),
        ]);
        self::assertNotNull($result);
        [$encoding, $data] = $result;
        self::assertSame('gzip', $encoding);
        self::assertSame($original, gzdecode($data));
    }

    public function testReturnsNullWhenNoCodecAvailable(): void
    {
        $original = str_repeat('payload-', 500);
        self::assertNull(Compression::compressWith($original, []));
    }

    public function testSmallBodyIsNotCompressedEvenWithCodecs(): void
    {
        // The size gate applies before the compressors.
        $small = str_repeat('a', Compression::MIN_COMPRESS_BYTES - 1);
        self::assertNull(Compression::compressWith($small, [
            self::compressor('br', static fn (string $b) => 'BR:' . $b),
        ]));
    }

    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public static function contentTypeProvider(): array
    {
        return [
            'null content type is compressible' => ['', true],
            'json is compressible' => ['application/json', true],
            'json with charset param is compressible' => ['application/json; charset=utf-8', true],
            'octet-stream is compressible (unknown binary)' => ['application/octet-stream', true],
            'png is not compressible' => ['image/png', false],
            'mp4 is not compressible' => ['video/mp4', false],
            'mp3 is not compressible' => ['audio/mpeg', false],
            'zip is not compressible' => ['application/zip', false],
            'docx is not compressible' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', false],
            'woff2 font is not compressible' => ['font/woff2', false],
            'bmp raw image is still compressible' => ['image/bmp', true],
            'wav raw audio is still compressible' => ['audio/wav', true],
            'svg+xml structured suffix is compressible' => ['image/svg+xml', true],
            'case-insensitive match' => ['IMAGE/PNG', false],
        ];
    }

    /** @dataProvider contentTypeProvider */
    public function testIsCompressibleContentType(string $contentType, bool $expected): void
    {
        self::assertSame($expected, Compression::isCompressibleContentType($contentType === '' ? null : $contentType));
    }

    private static function decode(string $encoding, string $data): string
    {
        if ($encoding === 'br') {
            self::assertTrue(function_exists('brotli_uncompress'), 'brotli extension needed to decode');
            // Called by name, so static analysis does not flag builds without the extension.
            $brotliUncompress = 'brotli_uncompress';
            $decoded = $brotliUncompress($data);
        } else {
            $decoded = gzdecode($data);
        }
        self::assertIsString($decoded);
        return $decoded;
    }
}
