<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use InvalidArgumentException;
use RuntimeException;

/**
 * Compresses request bodies with brotli at a quality from 0 to 11 (default 6).
 *
 * Needs the PECL {@code brotli} extension. The constructor throws without it, see {@see isAvailable()}.
 */
final class BrotliHttpCompressor implements HttpCompressorInterface
{
    public const MIN_QUALITY = 0;
    public const MAX_QUALITY = 11;
    public const DEFAULT_QUALITY = 6;

    private int $quality;

    /**
     * @throws InvalidArgumentException if {@code $quality} is out of range
     * @throws RuntimeException if the PECL brotli extension is not loaded
     */
    public function __construct(int $quality = self::DEFAULT_QUALITY)
    {
        if ($quality < self::MIN_QUALITY || $quality > self::MAX_QUALITY) {
            throw new InvalidArgumentException(sprintf(
                'BrotliHttpCompressor: $quality must be between %d and %d, got %d',
                self::MIN_QUALITY,
                self::MAX_QUALITY,
                $quality
            ));
        }
        if (!self::isAvailable()) {
            throw new RuntimeException('BrotliHttpCompressor needs the PECL brotli extension.');
        }
        $this->quality = $quality;
    }

    /** Whether the PECL {@code brotli} extension is loaded. */
    public static function isAvailable(): bool
    {
        return function_exists('brotli_compress');
    }

    public function contentEncoding(): string
    {
        return 'br';
    }

    public function compress(string $body): ?string
    {
        // Called by name, so static analysis does not flag builds without the extension.
        $brotliCompress = 'brotli_compress';
        $compressed = $brotliCompress($body, $this->quality);
        return is_string($compressed) ? $compressed : null;
    }
}
