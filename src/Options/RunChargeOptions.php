<?php

declare(strict_types=1);

namespace Apify\Client\Options;

/** Configures charging for a pay-per-event Actor run. */
final class RunChargeOptions
{
    public function __construct(
        /** The name of the event to charge for. Required. */
        public readonly string $eventName,
        /** The number of times to charge the event (defaults to 1). */
        public readonly ?int $count = null,
        /**
         * A key that deduplicates the charge across retries. By default {@code "{runId}-{eventName}-{timestampMillis}-{random}"}.
         * It expires 3 minutes after the charge, and a later request that reuses it creates a new charge.
         */
        public readonly ?string $idempotencyKey = null,
        /**
         * The ID of the Server Actor request being charged, from the {@code X-Actor-Request-ID} header of the
         * incoming request. Required for runs of multi-tenant Server Actors, where it identifies the request the
         * charge belongs to. Runs of single-tenant Server Actors ignore it.
         */
        public readonly ?string $requestId = null,
    ) {
    }

    /** @internal */
    public function countValue(): int
    {
        return $this->count ?? 1;
    }
}
