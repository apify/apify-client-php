<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

/**
 * Optional request-body compression.
 *
 * Large request bodies are compressed before being sent, saving bandwidth on uploads (Actor inputs,
 * key-value-store records, dataset item batches, ...). Brotli ({@code Content-Encoding: br}) is
 * preferred when available and gzip ({@code Content-Encoding: gzip}) is used as a fallback. The API
 * accepts br/gzip/deflate as request {@code Content-Encoding} (see apify-docs #2750), so preferring
 * brotli is valid. Note this differs from the reference JS client, which compresses request bodies
 * with gzip only; the size threshold below is shared with the reference, the codec choice is not.
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
     * Brotli quality level. Level 6 trades a little compression ratio for much faster compression
     * than the brotli default (11), which suits request-body sizes.
     */
    private const BROTLI_QUALITY = 6;

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
