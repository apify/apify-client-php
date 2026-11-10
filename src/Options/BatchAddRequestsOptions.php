<?php

declare(strict_types=1);

namespace Apify\Client\Options;

/**
 * Options for adding requests in batches. Requests the API reports as unprocessed, typically because of rate
 * limiting, are retried with exponential backoff. Batches are sent one after another, so there is no
 * {@code maxParallel} option.
 */
final class BatchAddRequestsOptions
{
    /** The default number of retry rounds for unprocessed requests. */
    public const DEFAULT_MAX_UNPROCESSED_RETRIES = 3;

    /** The default minimum delay before a retry, in milliseconds. */
    public const DEFAULT_MIN_DELAY_MILLIS = 500;

    public readonly int $maxUnprocessedRequestsRetries;
    public readonly int $minDelayBetweenUnprocessedRequestsRetriesMillis;

    public function __construct(
        int $maxUnprocessedRequestsRetries = self::DEFAULT_MAX_UNPROCESSED_RETRIES,
        int $minDelayBetweenUnprocessedRequestsRetriesMillis = self::DEFAULT_MIN_DELAY_MILLIS,
    ) {
        $this->maxUnprocessedRequestsRetries = max(0, $maxUnprocessedRequestsRetries);
        $this->minDelayBetweenUnprocessedRequestsRetriesMillis = max(0, $minDelayBetweenUnprocessedRequestsRetriesMillis);
    }
}
