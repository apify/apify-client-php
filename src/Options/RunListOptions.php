<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/** Filters for listing runs. {@code startedAfter} and {@code startedBefore} work only for Actor and task runs. */
final class RunListOptions
{
    /**
     * @param list<string>|null $status filters by run status, for example {@code "SUCCEEDED"}; sent comma-separated
     */
    public function __construct(
        public readonly ?array $status = null,
        /** Filter to runs started after this ISO-8601 timestamp. */
        public readonly ?string $startedAfter = null,
        /** Filter to runs started before this ISO-8601 timestamp. */
        public readonly ?string $startedBefore = null,
        /**
         * Filters by the deployment of Server Actor runs ({@code SINGLE_TENANT}, {@code MULTI_TENANT_FREE_USERS},
         * {@code MULTI_TENANT_PAYING_USERS}, {@code MULTI_TENANT_OWNER_PAID}); sent comma-separated. Works for
         * Actor runs and all runs. The API then requires {@code status} to hold only non-terminal statuses
         * ({@code READY}, {@code RUNNING}, {@code TIMING-OUT}, {@code ABORTING}).
         *
         * @var list<string>|null
         */
        public readonly ?array $deployment = null,
    ) {
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addCsv('status', $this->status !== null ? array_values($this->status) : null)
            ->addString('startedAfter', $this->startedAfter)
            ->addString('startedBefore', $this->startedBefore)
            ->addCsv('deployment', $this->deployment !== null ? array_values($this->deployment) : null);
    }
}
