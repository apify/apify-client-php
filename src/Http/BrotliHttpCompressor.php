<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use InvalidArgumentException;

/**
 * Compresses request bodies with brotli at a configurable quality (0-11, default 6 — matching the
 * client's own default). Requires the optional PECL {@code brotli} extension; when it is not loaded,
 * {@see compress()} returns {@code null} (the body is sent uncompressed) rather than throwing, since
 * compression is always best-effort.
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
        // Called indirectly: brotli_compress only exists when the PECL brotli extension is loaded, so a
        // direct call would be an unresolved reference for static analysis on builds without it.
        $brotliCompress = 'brotli_compress';
        $compressed = $brotliCompress($body, $this->quality);
        return is_string($compressed) ? $compressed : null;
    }
}
