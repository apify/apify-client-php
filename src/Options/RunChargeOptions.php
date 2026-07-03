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
         * A key that deduplicates the charge across retries. If unset, one is auto-generated as
         * {@code "{runId}-{eventName}-{timestampMillis}-{random}"}, matching the reference client.
         */
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    /** @internal */
    public function countValue(): int
    {
        return $this->count ?? 1;
    }
}
