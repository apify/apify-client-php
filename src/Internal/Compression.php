<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

/**
 * Optional request-body compression, matching the reference JS client's behaviour.
 *
 * Large request bodies are compressed before being sent, saving bandwidth on uploads (Actor inputs,
 * key-value-store records, dataset item batches, ...). Brotli ({@code Content-Encoding: br}) is
 * preferred when available and gzip ({@code Content-Encoding: gzip}) is used as a fallback.
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
        if (strlen($body) < self::MIN_COMPRESS_BYTES) {
            return null;
        }

        if (function_exists('brotli_compress')) {
            // Called indirectly: brotli_compress only exists when the PECL brotli extension is
            // loaded, so a direct call would be an unresolved reference for static analysis on the
            // (common) PHP builds without the extension.
            $brotliCompress = 'brotli_compress';
            $compressed = $brotliCompress($body, self::BROTLI_QUALITY);
            if (is_string($compressed)) {
                return ['br', $compressed];
            }
        }

        if (function_exists('gzencode')) {
            $compressed = gzencode($body);
            if ($compressed !== false) {
                return ['gzip', $compressed];
            }
        }

        return null;
    }

    private function __construct()
    {
    }
}
