<?php

declare(strict_types=1);

namespace Apify\Client\Options;

/**
 * Write options for storing a key-value-store record, mirroring the reference client's
 * {@code timeoutSecs}/{@code doNotRetryTimeouts}.
 */
final class SetRecordOptions
{
    public function __construct(
        /**
         * Per-request timeout for the upload, in seconds. Use it to shorten the wait for this upload;
         * defaults to (and is capped at) the client's configured overall request timeout, so a value
         * larger than that timeout has no effect.
         */
        public readonly ?int $timeoutSecs = null,
        /** If {@code true}, do not retry the upload when it fails with a request timeout. */
        public readonly bool $doNotRetryTimeouts = false,
    ) {
    }
}
