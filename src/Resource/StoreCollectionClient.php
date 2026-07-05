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
     * Lazily iterates over all Store Actors matching the options, fetching pages on demand. The
     * options' {@code limit} (if set) is used as the per-page size.
     *
     * @return Generator<int,ActorStoreListItem>
     */
    public function iterate(?StoreListOptions $options = null): Generator
    {
        $options ??= new StoreListOptions();
        $offset = $options->offset ?? 0;
        while (true) {
            $page = $this->list($options->withOffset($offset));
            $items = $page->getItems();
            foreach ($items as $item) {
                yield $item;
            }
            $offset += count($items);
            if ($items === [] || $offset >= $page->getTotal()) {
                return;
            }
        }
    }
}
