<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/**
 * Run-specific filters for listing runs. The {@code startedAfter}/{@code startedBefore} filters are
 * only honoured by the Actor-scoped and task-scoped run collections.
 */
final class RunListOptions
{
    /**
     * @param list<string>|null $status filter by one or more run statuses (e.g. {@code "SUCCEEDED"},
     *                                  {@code "RUNNING"}); sent as a comma-separated list
     */
    public function __construct(
        public readonly ?array $status = null,
        /** Filter to runs started after this ISO-8601 timestamp. */
        public readonly ?string $startedAfter = null,
        /** Filter to runs started before this ISO-8601 timestamp. */
        public readonly ?string $startedBefore = null,
        /**
         * Filter to runs of the given Actor Standby deployments ({@code SINGLE_TENANT},
         * {@code MULTI_TENANT_FREE_USERS}, {@code MULTI_TENANT_PAYING_USERS},
         * {@code MULTI_TENANT_OWNER_PAID}); sent as a comma-separated list. Honoured by the Actor-scoped
         * and account-wide run collections. The API requires {@code status} to be set to non-terminal
         * statuses only ({@code READY}, {@code RUNNING}, {@code TIMING-OUT}, {@code ABORTING}) when this
         * filter is used.
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
