<?php

declare(strict_types=1);

namespace Apify\Client\Options;

/** Options of a {@see \Apify\Client\Resource\RequestQueueClient}. */
final class RequestQueueClientOptions
{
    public function __construct(
        /**
         * A stable key that identifies the client to the queue. Needed to operate on locks the client created, and lets
         * the API detect several clients on one queue.
         */
        public readonly ?string $clientKey = null,
        /** The request timeout in seconds for the calls of the client. {@code null} uses the client-wide timeout. */
        public readonly ?float $timeoutSecs = null,
    ) {
    }
}
