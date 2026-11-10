<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/** Options for listing datasets, key-value stores and request queues: the {@code unnamed} and {@code ownership} filters and the pagination. */
final class StorageListOptions
{
    public function __construct(
        /** Number of items to skip from the beginning of the list. */
        public readonly ?int $offset = null,
        /** Maximum number of items to return. */
        public readonly ?int $limit = null,
        /** If {@code true}, return items newest-first. */
        public readonly ?bool $desc = null,
        /** If {@code true}, include unnamed storages in the result. */
        public readonly ?bool $unnamed = null,
        /** Filter by ownership (e.g. {@code "OWNED"} / {@code "ACCESSIBLE"}). */
        public readonly ?string $ownership = null,
    ) {
    }

    /** A copy with a new {@code offset} and {@code limit} and the same filters. Used to request successive pages. */
    public function withPagination(?int $offset, ?int $limit): self
    {
        return new self($offset, $limit, $this->desc, $this->unnamed, $this->ownership);
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addInt('offset', $this->offset)
            ->addInt('limit', $this->limit)
            ->addBool('desc', $this->desc)
            ->addBool('unnamed', $this->unnamed)
            ->addString('ownership', $this->ownership);
    }
}
