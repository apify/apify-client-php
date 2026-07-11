<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/** Options for listing the account's Actors. */
final class ActorListOptions
{
    public function __construct(
        /** Number of Actors to skip. */
        public readonly ?int $offset = null,
        /** Maximum number of Actors to return. */
        public readonly ?int $limit = null,
        /** If {@code true}, return Actors newest-first. */
        public readonly ?bool $desc = null,
        /** If {@code true}, return only Actors owned by the current user. */
        public readonly ?bool $my = null,
        /** The sort field (e.g. {@code "createdAt"}, {@code "stats.lastRunStartedAt"}). */
        public readonly ?string $sortBy = null,
    ) {
    }

    /**
     * Returns a copy of these options with a new {@code offset}/{@code limit}, preserving the other
     * filters. Used by lazy iteration to request successive pages.
     */
    public function withPagination(?int $offset, ?int $limit): self
    {
        return new self($offset, $limit, $this->desc, $this->my, $this->sortBy);
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addInt('offset', $this->offset)
            ->addInt('limit', $this->limit)
            ->addBool('desc', $this->desc)
            ->addBool('my', $this->my)
            ->addString('sortBy', $this->sortBy);
    }
}
