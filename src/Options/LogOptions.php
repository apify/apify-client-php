<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/** Configures log retrieval/streaming. */
final class LogOptions
{
    public function __construct(
        /** If {@code true}, return the unprocessed log content (no platform post-processing). */
        public readonly ?bool $raw = null,
        /** If {@code true}, set Content-Disposition so the log is served as a download. */
        public readonly ?bool $download = null,
    ) {
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addBool('raw', $this->raw)->addBool('download', $this->download);
    }
}
