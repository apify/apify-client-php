<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/** Configures listing keys in a key-value store. */
final class ListKeysOptions
{
    public function __construct(
        /** Maximum number of keys to return. */
        public readonly ?int $limit = null,
        /** List keys after this one (for pagination). */
        public readonly ?string $exclusiveStartKey = null,
        /** Restrict the listing to keys with this prefix. */
        public readonly ?string $prefix = null,
        /** Restrict the listing to a named collection of keys. */
        public readonly ?string $collection = null,
        /** A pre-shared URL signature granting access without an API token. */
        public readonly ?string $signature = null,
    ) {
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addInt('limit', $this->limit)
            ->addString('exclusiveStartKey', $this->exclusiveStartKey)
            ->addString('prefix', $this->prefix)
            ->addString('collection', $this->collection)
            ->addString('signature', $this->signature);
    }
}
