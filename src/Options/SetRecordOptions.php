<?php

declare(strict_types=1);

namespace Apify\Client\Options;

/** Options for storing a key-value store record. */
final class SetRecordOptions
{
    public function __construct(
        /**
         * The timeout of the upload: seconds, a tier name or {@code 'noTimeout'} (see {@see \Apify\Client\Internal\TimeoutTiers}).
         * Defaults to the {@code long} tier. Seconds are capped at {@code timeoutMaxSecs}.
         */
        public readonly int|float|string|null $timeoutSecs = null,
        /** If {@code true}, do not retry the upload when it fails with a request timeout. */
        public readonly bool $doNotRetryTimeouts = false,
    ) {
    }
}
