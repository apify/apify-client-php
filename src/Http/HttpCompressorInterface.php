<?php

declare(strict_types=1);

namespace Apify\Client\Http;

/**
 * A pluggable request-body compressor, matching the reference client's {@code HttpCompressor}. Pass
 * an implementation (or one of the built-ins, {@see BrotliHttpCompressor}/{@see GzipHttpCompressor})
 * as {@code ApifyClient}'s {@code compression} constructor option to control which algorithm —  and at
 * what quality — compresses request bodies, instead of the client's default best-effort choice.
 *
 * Only bodies that already pass the client's other compression gates reach {@see compress()}: at
 * least {@see \Apify\Client\Internal\Compression::MIN_COMPRESS_BYTES}, a compressible content type,
 * and no caller-supplied {@code Content-Encoding}.
 */
interface HttpCompressorInterface
{
    /** The value sent in the {@code Content-Encoding} header for a body this compressor encoded. */
    public function contentEncoding(): string;

    /**
     * Compresses {@code $body}, or returns {@code null} to send it uncompressed (e.g. the codec is
     * unavailable in this PHP build). A compressor is never required to succeed: compression is
     * always a best-effort optimization, never a correctness requirement.
     */
    public function compress(string $body): ?string;
}
