<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use InvalidArgumentException;

/**
 * Compresses request bodies with brotli at a quality from 0 to 11 (default 6).
 *
 * Needs the PECL {@code brotli} extension. Without it, {@see compress()} returns {@code null} and the body is
 * sent uncompressed.
 */
final class BrotliHttpCompressor implements HttpCompressorInterface
{
    private const DEFAULT_QUALITY = 6;

    private int $quality;

    public function __construct(int $quality = self::DEFAULT_QUALITY)
    {
        if ($quality < 0 || $quality > 11) {
            throw new InvalidArgumentException('BrotliHttpCompressor: $quality must be between 0 and 11, got ' . $quality);
        }
        $this->quality = $quality;
    }

    public function contentEncoding(): string
    {
        return 'br';
    }

    public function compress(string $body): ?string
    {
        if (!function_exists('brotli_compress')) {
            return null;
        }
        // Called by name, so static analysis does not flag builds without the extension.
        $brotliCompress = 'brotli_compress';
        $compressed = $brotliCompress($body, $this->quality);
        return is_string($compressed) ? $compressed : null;
    }
}
