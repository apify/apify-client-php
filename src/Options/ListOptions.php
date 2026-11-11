<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/** Offset and limit pagination, shared by most {@code list} endpoints. A {@code null} field uses the API default. */
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

    /** A copy with a new {@code offset} and {@code limit} and the same filters. Used to request successive pages. */
    public function withPagination(?int $offset, ?int $limit): self
    {
        return new self($offset, $limit, $this->desc);
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addInt('offset', $this->offset)
            ->addInt('limit', $this->limit)
            ->addBool('desc', $this->desc);
    }
}
