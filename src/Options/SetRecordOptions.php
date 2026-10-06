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
         * Per-request timeout for the upload: a number of seconds, a timeout tier name, or {@code
         * 'noTimeout'} (see {@see \Apify\Client\Internal\TimeoutTiers}). Defaults to the {@code long}
         * tier. A numeric value is capped at the configured {@code timeoutMaxSecs}.
         */
        public readonly int|float|string|null $timeoutSecs = null,
        /** If {@code true}, do not retry the upload when it fails with a request timeout. */
        public readonly bool $doNotRetryTimeouts = false,
    ) {
    }
}
