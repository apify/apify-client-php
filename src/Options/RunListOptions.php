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
    ) {
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addCsv('status', $this->status !== null ? array_values($this->status) : null)
            ->addString('startedAfter', $this->startedAfter)
            ->addString('startedBefore', $this->startedBefore);
    }
}
