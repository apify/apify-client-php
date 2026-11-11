<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use InvalidArgumentException;
use RuntimeException;

/**
 * Compresses request bodies with gzip at a quality from 1 to 9 (default 6).
 *
 * Needs the {@code zlib} extension. The constructor throws without it, see {@see isAvailable()}.
 */
final class GzipHttpCompressor implements HttpCompressorInterface
{
    public const MIN_QUALITY = 1;
    public const MAX_QUALITY = 9;
    public const DEFAULT_QUALITY = 6;

    private int $quality;

    /**
     * @throws InvalidArgumentException if {@code $quality} is out of range
     * @throws RuntimeException if the zlib extension is not loaded
     */
    public function __construct(int $quality = self::DEFAULT_QUALITY)
    {
        if ($quality < self::MIN_QUALITY || $quality > self::MAX_QUALITY) {
            throw new InvalidArgumentException(sprintf(
                'GzipHttpCompressor: $quality must be between %d and %d, got %d',
                self::MIN_QUALITY,
                self::MAX_QUALITY,
                $quality
            ));
        }
        if (!self::isAvailable()) {
            throw new RuntimeException('GzipHttpCompressor needs the zlib extension.');
        }
        $this->quality = $quality;
    }

    /** Whether the {@code zlib} extension is loaded. */
    public static function isAvailable(): bool
    {
        return function_exists('gzencode');
    }

    public function contentEncoding(): string
    {
        return 'gzip';
    }

    public function compress(string $body): ?string
    {
        $compressed = gzencode($body, $this->quality);
        return is_string($compressed) ? $compressed : null;
    }
}
