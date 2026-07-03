<?php

declare(strict_types=1);

namespace Apify\Client\Options;

/**
 * Tuning options for batch request adding, mirroring the reference client. Requests the API reports
 * as unprocessed (typically due to rate limiting) are automatically retried with exponential backoff.
 */
final class BatchAddRequestsOptions
{
    /** Default number of retry rounds for unprocessed requests (matches the reference client). */
    public const DEFAULT_MAX_UNPROCESSED_RETRIES = 3;

    /** Default maximum number of batch API calls made in parallel (matches the reference client). */
    public const DEFAULT_MAX_PARALLEL = 5;

    /** Default minimum delay before retrying unprocessed requests (matches the reference client). */
    public const DEFAULT_MIN_DELAY_MILLIS = 500;

    public readonly int $maxUnprocessedRequestsRetries;
    public readonly int $maxParallel;
    public readonly int $minDelayBetweenUnprocessedRequestsRetriesMillis;

    public function __construct(
        int $maxUnprocessedRequestsRetries = self::DEFAULT_MAX_UNPROCESSED_RETRIES,
        int $maxParallel = self::DEFAULT_MAX_PARALLEL,
        int $minDelayBetweenUnprocessedRequestsRetriesMillis = self::DEFAULT_MIN_DELAY_MILLIS,
    ) {
        $this->maxUnprocessedRequestsRetries = max(0, $maxUnprocessedRequestsRetries);
        $this->maxParallel = max(1, $maxParallel);
        $this->minDelayBetweenUnprocessedRequestsRetriesMillis = max(0, $minDelayBetweenUnprocessedRequestsRetriesMillis);
    }
}
