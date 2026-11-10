<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use InvalidArgumentException;

/**
 * Compresses request bodies with gzip at a quality from 1 to 9 (default 6).
 *
 * Needs the {@code zlib} extension. Without it, {@see compress()} returns {@code null} and the body is sent
 * uncompressed.
 */
final class GzipHttpCompressor implements HttpCompressorInterface
{
    private const DEFAULT_QUALITY = 6;

    private int $quality;

    public function __construct(int $quality = self::DEFAULT_QUALITY)
    {
        if ($quality < 1 || $quality > 9) {
            throw new InvalidArgumentException('GzipHttpCompressor: $quality must be between 1 and 9, got ' . $quality);
        }
        $this->quality = $quality;
    }

    public function contentEncoding(): string
    {
        return 'gzip';
    }

    public function compress(string $body): ?string
    {
        if (!function_exists('gzencode')) {
            return null;
        }
        $compressed = gzencode($body, $this->quality);
        return is_string($compressed) ? $compressed : null;
    }
}
