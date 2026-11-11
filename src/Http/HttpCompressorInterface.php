<?php

declare(strict_types=1);

namespace Apify\Client\Http;

/**
 * Compresses request bodies. Pass an implementation, or a built-in one ({@see BrotliHttpCompressor},
 * {@see GzipHttpCompressor}, {@see ZstdHttpCompressor}), as the {@code compression} option of
 * {@see \Apify\Client\ApifyClient} to choose the algorithm and its quality.
 *
 * Only bodies of at least {@see \Apify\Client\Internal\Compression::MIN_COMPRESS_BYTES} with a compressible
 * content type and no caller-set {@code Content-Encoding} reach {@see compress()}.
 */
interface HttpCompressorInterface
{
    /** The {@code Content-Encoding} value of a body this compressor encoded. */
    public function contentEncoding(): string;

    /** Compresses {@code $body}, or returns {@code null} to send it uncompressed. */
    public function compress(string $body): ?string;
}
