<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Http\BrotliHttpCompressor;
use Apify\Client\Http\GzipHttpCompressor;
use Apify\Client\Http\HttpCompressorInterface;
use Apify\Client\Http\ZstdHttpCompressor;
use RuntimeException;

/**
 * Request-body compression: brotli when the PECL {@code brotli} extension is loaded, gzip otherwise.
 *
 * The default choice is best-effort. A body is sent unchanged when it is small, already compressed, or no codec is
 * available.
 *
 * @internal
 */
final class Compression
{
    /** Minimum body size in bytes worth compressing. */
    public const MIN_COMPRESS_BYTES = 1024;

    /** Media type prefixes whose payloads already carry their own compression. */
    private const ALREADY_COMPRESSED_PREFIXES = ['audio/', 'image/', 'video/'];

    /** Exact media types whose payloads already carry their own compression. */
    private const ALREADY_COMPRESSED_TYPES = [
        'application/epub+zip',
        'application/gzip',
        'application/java-archive',
        'application/vnd.android.package-archive',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.rar',
        'application/x-7z-compressed',
        'application/x-bzip',
        'application/x-bzip2',
        'application/x-gzip',
        'application/x-rar-compressed',
        'application/x-xz',
        'application/x-zip-compressed',
        'application/zip',
        'application/zstd',
        'font/woff',
        'font/woff2',
    ];

    /** Raw media types under an already-compressed prefix, which are still worth compressing. */
    private const COMPRESSIBLE_TYPES = [
        'audio/aiff',
        'audio/basic',
        'audio/l16',
        'audio/l24',
        'audio/midi',
        'audio/vnd.wave',
        'audio/wav',
        'audio/wave',
        'audio/x-aiff',
        'audio/x-wav',
        'image/bmp',
        'image/tiff',
        'image/vnd.adobe.photoshop',
        'image/vnd.microsoft.icon',
        'image/x-icon',
        'image/x-ms-bmp',
    ];

    /** Structured syntax suffixes that mark a media type as text, whatever its prefix. */
    private const COMPRESSIBLE_SUFFIXES = ['+json', '+xml'];

    /**
     * Whether a request body with the given {@code Content-Type} is worth compressing. Media and archives are
     * not, since they carry their own compression. A missing content type counts as compressible.
     */
    public static function isCompressibleContentType(?string $contentType): bool
    {
        if ($contentType === null || $contentType === '') {
            return true;
        }

        // Drop parameters such as "; charset=utf-8".
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        if (in_array($mediaType, self::COMPRESSIBLE_TYPES, true)) {
            return true;
        }
        foreach (self::COMPRESSIBLE_SUFFIXES as $suffix) {
            if (str_ends_with($mediaType, $suffix)) {
                return true;
            }
        }

        if (in_array($mediaType, self::ALREADY_COMPRESSED_TYPES, true)) {
            return false;
        }
        foreach (self::ALREADY_COMPRESSED_PREFIXES as $prefix) {
            if (str_starts_with($mediaType, $prefix)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compresses {@code $body} with brotli if the extension is loaded, else with gzip.
     *
     * @return array{0: string, 1: string}|null the {@code Content-Encoding} value and the compressed body, or
     *                                          {@code null} to send the body unchanged
     */
    public static function maybeCompress(string $body): ?array
    {
        $compressors = [];
        if (BrotliHttpCompressor::isAvailable()) {
            $compressors[] = new BrotliHttpCompressor();
        }
        if (GzipHttpCompressor::isAvailable()) {
            $compressors[] = new GzipHttpCompressor();
        }
        return self::compressWith($body, $compressors);
    }

    /**
     * Applies the size gate and compresses with the first compressor that succeeds.
     *
     * @param list<HttpCompressorInterface> $compressors in order of preference
     * @return array{0: string, 1: string}|null
     */
    public static function compressWith(string $body, array $compressors): ?array
    {
        if (strlen($body) < self::MIN_COMPRESS_BYTES) {
            return null;
        }

        foreach ($compressors as $compressor) {
            $compressed = $compressor->compress($body);
            if ($compressed !== null) {
                return [$compressor->contentEncoding(), $compressed];
            }
        }

        return null;
    }

    /**
     * Resolves a {@code compression} option to a compressor, or {@code null} for the default choice.
     *
     * @param string $option the name of the option's owner, for the error message
     * @throws RuntimeException if the option is an unknown algorithm name, or names a codec whose extension is
     *                          not loaded
     */
    public static function resolveCompressor(string|HttpCompressorInterface|null $compression, string $option): ?HttpCompressorInterface
    {
        return match (true) {
            $compression === null => null,
            $compression instanceof HttpCompressorInterface => $compression,
            $compression === 'brotli' => new BrotliHttpCompressor(),
            $compression === 'gzip' => new GzipHttpCompressor(),
            $compression === 'zstd' => new ZstdHttpCompressor(),
            default => throw new RuntimeException(
                sprintf('%s: unknown compression "%s"; expected "brotli", "gzip", "zstd", or a HttpCompressorInterface', $option, $compression)
            ),
        };
    }

    private function __construct()
    {
    }
}
