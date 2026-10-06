<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

/**
 * Optional request-body compression, matching the reference JS client's behaviour.
 *
 * Large request bodies are compressed before being sent, saving bandwidth on uploads (Actor inputs,
 * key-value-store records, dataset item batches, ...). Brotli ({@code Content-Encoding: br}) is
 * preferred when available and gzip ({@code Content-Encoding: gzip}) is used as a fallback — the same
 * codec choice, brotli quality (6), and size threshold (1024 bytes) as the reference client's
 * {@code maybeCompressValue}. The API accepts br/gzip/deflate as request {@code Content-Encoding}
 * (see apify-docs #2750), so preferring brotli is valid.
 *
 * In PHP, brotli lives in the optional PECL {@code brotli} extension, which is frequently absent,
 * while gzip ({@code gzencode}) ships with the standard {@code zlib} extension. We therefore prefer
 * brotli only when the extension is loaded and fall back to gzip otherwise. Compression is
 * best-effort: if neither codec is available (or a payload is too small), the body is sent
 * unchanged rather than raising an error.
 *
 * @internal
 */
final class Compression
{
    /**
     * Minimum body size (in bytes) worth compressing. Below this the CPU cost and the few bytes of
     * codec framing outweigh the savings, so the body is left uncompressed. Matches the reference
     * client's {@code MIN_COMPRESS_BYTES}.
     */
    public const MIN_COMPRESS_BYTES = 1024;

    /**
     * Brotli quality level. Level 6 mirrors the reference client and trades a little ratio for much
     * faster compression than the brotli default (11).
     */
    private const BROTLI_QUALITY = 6;

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

    /** Uncompressed media types that sit under an already-compressed prefix, so compressing them still pays off. */
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

    /** Structured syntax suffixes marking a media type as text even under an already-compressed prefix. */
    private const COMPRESSIBLE_SUFFIXES = ['+json', '+xml'];

    /**
     * Decides whether a request body with the given {@code Content-Type} is worth compressing, matching
     * the reference client's {@code isCompressibleContentType}.
     *
     * Images, audio, video and archives already carry their own compression; running them through
     * brotli or gzip burns CPU, holds a second full copy of the body in memory, and usually produces
     * output slightly larger than the input. Formats that are raw despite such a media type (e.g.
     * {@code image/bmp}, {@code audio/wav}) are still compressed, as are structured-syntax subtypes
     * such as {@code image/svg+xml}. A body with no content type, or {@code application/octet-stream}
     * (the catch-all for unknown binary data and {@code setRecord()}'s fallback), is assumed compressible.
     */
    public static function isCompressibleContentType(?string $contentType): bool
    {
        if ($contentType === null || $contentType === '') {
            return true;
        }

        // Content-Type is case-insensitive and may carry parameters, e.g. "text/plain; charset=utf-8".
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
     * Returns {@code [encoding, compressedBody]} when {@code $body} should be sent compressed, or
     * {@code null} to send it unchanged. {@code $encoding} is the value for the
     * {@code Content-Encoding} header ({@code 'br'} or {@code 'gzip'}).
     *
     * Only byte payloads at least {@see MIN_COMPRESS_BYTES} long are compressed. PHP strings are
     * byte strings, so {@code strlen()} already measures the encoded size.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function maybeCompress(string $body): ?array
    {
        return self::compressWith($body, self::brotliEncoder(), self::gzipEncoder());
    }

    /**
     * Size gate plus codec selection, split out from {@see maybeCompress} so both the brotli and the
     * gzip path can be exercised by tests regardless of which extensions the host PHP build loaded.
     *
     * Brotli ({@code br}) is preferred over gzip when its encoder is available; each encoder returns
     * the compressed bytes as a string, or a non-string on failure, in which case the next codec is
     * tried. Returns {@code null} when the body is below {@see MIN_COMPRESS_BYTES} or no codec
     * succeeds. A {@code null} encoder means that codec is unavailable and is skipped.
     *
     * @param (callable(string): mixed)|null $brotli brotli encoder, or {@code null} when the PECL brotli extension is absent
     * @param (callable(string): mixed)|null $gzip   gzip encoder, or {@code null} when zlib is absent
     * @return array{0: string, 1: string}|null
     */
    public static function compressWith(string $body, ?callable $brotli, ?callable $gzip): ?array
    {
        if (strlen($body) < self::MIN_COMPRESS_BYTES) {
            return null;
        }

        if ($brotli !== null) {
            $compressed = $brotli($body);
            if (is_string($compressed)) {
                return ['br', $compressed];
            }
        }

        if ($gzip !== null) {
            $compressed = $gzip($body);
            if (is_string($compressed)) {
                return ['gzip', $compressed];
            }
        }

        return null;
    }

    /**
     * The brotli encoder for this build, or {@code null} when the PECL {@code brotli} extension is not
     * loaded. Frequently absent, since brotli is not part of PHP's standard distribution.
     *
     * @return (callable(string): mixed)|null
     */
    private static function brotliEncoder(): ?callable
    {
        if (!function_exists('brotli_compress')) {
            return null;
        }

        // Called indirectly: brotli_compress only exists when the PECL brotli extension is loaded, so
        // a direct call would be an unresolved reference for static analysis on the (common) PHP
        // builds without the extension.
        $brotliCompress = 'brotli_compress';
        return static fn (string $body) => $brotliCompress($body, self::BROTLI_QUALITY);
    }

    /**
     * The gzip encoder for this build, or {@code null} when {@code gzencode} (zlib) is unavailable.
     * Ships with PHP's standard {@code zlib} extension, so it is the near-universal fallback.
     *
     * @return (callable(string): mixed)|null
     */
    private static function gzipEncoder(): ?callable
    {
        if (!function_exists('gzencode')) {
            return null;
        }

        return static fn (string $body) => gzencode($body);
    }

    private function __construct()
    {
    }
}
