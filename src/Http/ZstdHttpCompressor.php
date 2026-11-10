<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use InvalidArgumentException;
use RuntimeException;

/**
 * Compresses request bodies with zstd at a quality from 1 to 19 (default 3).
 *
 * Needs the PECL {@code zstd} extension. The constructor throws without it, see {@see isAvailable()}.
 */
final class ZstdHttpCompressor implements HttpCompressorInterface
{
    public const MIN_QUALITY = 1;

    /**
     * zstd itself goes up to 22, but levels 20 to 22 use a window above the 8 MB that RFC 9659 requires HTTP
     * decoders to support. Level 19 is the last one within the limit.
     */
    public const MAX_QUALITY = 19;

    public const DEFAULT_QUALITY = 3;

    private int $quality;

    /**
     * @throws InvalidArgumentException if {@code $quality} is out of range
     * @throws RuntimeException if the PECL zstd extension is not loaded
     */
    public function __construct(int $quality = self::DEFAULT_QUALITY)
    {
        if ($quality < self::MIN_QUALITY || $quality > self::MAX_QUALITY) {
            throw new InvalidArgumentException(sprintf(
                'ZstdHttpCompressor: $quality must be between %d and %d, got %d',
                self::MIN_QUALITY,
                self::MAX_QUALITY,
                $quality
            ));
        }
        if (!self::isAvailable()) {
            throw new RuntimeException('ZstdHttpCompressor needs the PECL zstd extension.');
        }
        $this->quality = $quality;
    }

    /** Whether the PECL {@code zstd} extension is loaded. */
    public static function isAvailable(): bool
    {
        return function_exists('zstd_compress');
    }

    public function contentEncoding(): string
    {
        return 'zstd';
    }

    public function compress(string $body): ?string
    {
        // Called by name, so static analysis does not flag builds without the extension.
        $zstdCompress = 'zstd_compress';
        $compressed = $zstdCompress($body, $this->quality);
        return is_string($compressed) ? $compressed : null;
    }
}
