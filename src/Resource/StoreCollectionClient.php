<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\ActorStoreListItem;
use Apify\Client\Model\PaginationList;
use Apify\Client\Options\StoreListOptions;
use Generator;

/** A client for browsing the Apify Store ({@code GET /v2/store}). */
final class StoreCollectionClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $baseUrl)
    {
        $this->ctx = ResourceContext::collection($http, $baseUrl, 'store');
    }

    /**
     * Returns a single page of Store Actors matching the options.
     *
     * @return PaginationList<ActorStoreListItem>
     */
    public function list(?StoreListOptions $options = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new StoreListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new ActorStoreListItem($d));
    }

    /**
     * Lazily iterates over Store Actors matching the options, fetching pages on demand.
     *
     * The options' {@code limit} caps the total number of Actors yielded across all pages ({@code
     * null} = all); {@code $chunkSize} is the per-page size ({@code null} = the server default).
     *
     * @return Generator<int,ActorStoreListItem>
     */
    public function iterate(?StoreListOptions $options = null, ?int $chunkSize = null): Generator
    {
        $options ??= new StoreListOptions();
        return ResourceContext::paginateOffset(
            $options->offset ?? 0,
            $options->limit,
            $chunkSize,
            fn (int $offset, ?int $pageLimit) => $this->list($options->withPagination($offset, $pageLimit)),
        );
    }
}
