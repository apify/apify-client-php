<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\KeyValueStore;
use Apify\Client\Model\PaginationList;
use Apify\Client\Options\StorageListOptions;
use Generator;

/** A client for the key-value store collection ({@code GET/POST /v2/key-value-stores}). */
final class KeyValueStoreCollectionClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $baseUrl)
    {
        $this->ctx = ResourceContext::collection($http, $baseUrl, 'key-value-stores');
    }

    /**
     * Lists key-value stores.
     *
     * @return PaginationList<KeyValueStore>
     */
    public function list(?StorageListOptions $options = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new StorageListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new KeyValueStore($d));
    }

    /**
     * Lazily iterates over key-value stores, fetching pages on demand. The options' {@code limit}
     * caps the total number of stores yielded across all pages ({@code null} = all); {@code $chunkSize}
     * is the per-page size ({@code null} = the server default).
     *
     * @return Generator<int,KeyValueStore>
     */
    public function iterate(?StorageListOptions $options = null, ?int $chunkSize = null): Generator
    {
        $options ??= new StorageListOptions();
        return ResourceContext::paginateOffset(
            $options->offset ?? 0,
            $options->limit,
            $chunkSize,
            fn (int $offset, ?int $pageLimit) => $this->list($options->withPagination($offset, $pageLimit)),
        );
    }

    /**
     * Gets the store with the given name, creating it if it does not exist. An empty/{@code null}
     * name creates a new unnamed store. An optional {@code $schema} (an associative array) is sent
     * when creating the store, mirroring the reference client's {@code getOrCreate(name, { schema })}.
     *
     * @param array<string,mixed>|null $schema
     */
    public function getOrCreate(?string $name = null, ?array $schema = null): KeyValueStore
    {
        return new KeyValueStore($this->ctx->getOrCreateNamed($name, $schema));
    }
}
