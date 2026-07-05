<?php

declare(strict_types=1);

namespace Apify\Client\Options;

/**
 * Per-client options for a {@see \Apify\Client\Resource\RequestQueueClient}, mirroring the reference
 * client's {@code requestQueue(id, { clientKey, timeoutSecs })}.
 */
final class RequestQueueClientOptions
{
    public function __construct(
        /**
         * A stable client key identifying this client to the queue. Required to operate on locks the
         * client itself created, and lets the API detect whether multiple clients access the queue.
         */
        public readonly ?string $clientKey = null,
        /**
         * Overall per-request timeout (seconds) for this queue client's calls. When {@code null} the
         * shared client-wide timeout is used.
         */
        public readonly ?float $timeoutSecs = null,
    ) {
    }
}
