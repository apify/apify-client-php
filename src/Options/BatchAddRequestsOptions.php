<?php

declare(strict_types=1);

namespace Apify\Client\Options;

/**
 * Tuning options for batch request adding. Requests the API reports as unprocessed (typically due to
 * rate limiting) are automatically retried with exponential backoff.
 *
 * Note: unlike the reference JS client, this synchronous client sends the batch API calls
 * sequentially, so it exposes no {@code maxParallel} knob (it would have no effect).
 */
final class BatchAddRequestsOptions
{
    /** Default number of retry rounds for unprocessed requests (matches the reference client). */
    public const DEFAULT_MAX_UNPROCESSED_RETRIES = 3;

    /** Default minimum delay before retrying unprocessed requests (matches the reference client). */
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
