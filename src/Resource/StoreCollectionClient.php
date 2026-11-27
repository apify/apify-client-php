<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
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
    public function list(?StoreListOptions $options = null, int|float|string|null $timeoutSecs = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new StoreListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new ActorStoreListItem($d), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM));
    }

    /**
     * Lazily yields Store Actors matching the options. The {@code limit} option caps the total ({@code null} for all) and
     * {@code $chunkSize} sets the page size ({@code null} for the API's default).
     *
     * With {@code search}, the API ignores {@code offset} and returns one page of at most 100 Actors, so this makes
     * one request with the {@code limit} option and ignores {@code $chunkSize}.
     *
     * @return Generator<int,ActorStoreListItem>
     */
    public function iterate(?StoreListOptions $options = null, ?int $chunkSize = null, int|float|string|null $timeoutSecs = null): Generator
    {
        $options ??= new StoreListOptions();
        if ($options->search !== null && $options->search !== '') {
            return $this->iterateSearchPage($options, $timeoutSecs);
        }
        return ResourceContext::paginateOffset(
            $options->offset ?? 0,
            $options->limit,
            $chunkSize,
            fn (int $offset, ?int $pageLimit) => $this->list($options->withPagination($offset, $pageLimit), $timeoutSecs),
        );
    }

    /**
     * Yields the single page of a search, which the API cannot continue with an offset.
     *
     * @return Generator<int,ActorStoreListItem>
     */
    private function iterateSearchPage(StoreListOptions $options, int|float|string|null $timeoutSecs): Generator
    {
        yield from $this->list($options->withPagination(null, $options->limit), $timeoutSecs)->getItems();
    }
}
