<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use InvalidArgumentException;

/**
 * Compresses request bodies with zstd at a configurable level (1-19, default 3).
 *
 * Needs the PECL {@code zstd} extension. Without it, {@see compress()} returns {@code null} and the body is
 * sent uncompressed.
 */
final class ZstdHttpCompressor implements HttpCompressorInterface
{
    private const MIN_QUALITY = 1;

    /**
     * zstd itself goes up to 22, but levels 20 to 22 use a window above the 8 MB that RFC 9659 requires HTTP
     * decoders to support. Level 19 is the last one within the limit.
     */
    private const MAX_QUALITY = 19;

    private const DEFAULT_QUALITY = 3;

    private int $quality;

    public function __construct(int $quality = self::DEFAULT_QUALITY)
    {
        if ($quality < self::MIN_QUALITY || $quality > self::MAX_QUALITY) {
            throw new InvalidArgumentException('ZstdHttpCompressor: $quality must be between 1 and 19, got ' . $quality);
        }
        $this->quality = $quality;
    }

    public function contentEncoding(): string
    {
        return 'zstd';
    }

    public function compress(string $body): ?string
    {
        if (!function_exists('zstd_compress')) {
            return null;
        }
        // Called by name, so static analysis does not flag builds without the extension.
        $zstdCompress = 'zstd_compress';
        $compressed = $zstdCompress($body, $this->quality);
        return is_string($compressed) ? $compressed : null;
    }
}
