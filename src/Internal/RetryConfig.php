<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

/**
 * Retry/timeout policy for the orchestrating HTTP client.
 *
 * @internal
 */
final class RetryConfig
{
    public function __construct(
        /** Maximum number of retries (the request is attempted up to {@code maxRetries + 1} times). */
        public readonly int $maxRetries,
        /** Minimum delay between retries, in milliseconds; doubled on each retry (exponential backoff). */
        public readonly float $minDelayMillis,
        /** Upper bound on the (exponentially growing) inter-retry delay, in milliseconds. */
        public readonly float $maxDelayMillis,
        /** Overall per-request timeout budget, in seconds. Each attempt's timeout grows but is capped here. */
        public readonly float $timeoutSecs,
    ) {
    }
}
