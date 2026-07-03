<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/**
 * The standard offset/limit pagination shared by most {@code list} endpoints (builds, runs, tasks,
 * schedules, webhooks, Actor versions). All fields are optional; leave one {@code null} to use the
 * API default.
 */
final class ListOptions
{
    public function __construct(
        /** Number of items to skip from the beginning of the list. */
        public readonly ?int $offset = null,
        /** Maximum number of items to return. */
        public readonly ?int $limit = null,
        /** If {@code true}, return items newest-first. */
        public readonly ?bool $desc = null,
    ) {
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addInt('offset', $this->offset)
            ->addInt('limit', $this->limit)
            ->addBool('desc', $this->desc);
    }
}
