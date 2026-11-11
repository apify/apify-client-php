<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\PaginationList;
use Apify\Client\Model\RequestQueue;
use Apify\Client\Options\StorageListOptions;
use Generator;

/** A client for the request queue collection ({@code GET/POST /v2/request-queues}). */
final class RequestQueueCollectionClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $baseUrl)
    {
        $this->ctx = ResourceContext::collection($http, $baseUrl, 'request-queues');
    }

    /**
     * Lists request queues.
     *
     * @return PaginationList<RequestQueue>
     */
    public function list(?StorageListOptions $options = null, int|float|string|null $timeoutSecs = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new StorageListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new RequestQueue($d), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM));
    }

    /**
     * Lazily yields request queues. The {@code limit} option caps the total ({@code null} for all) and
     * {@code $chunkSize} sets the page size ({@code null} for the API's default).
     *
     * @return Generator<int,RequestQueue>
     */
    public function iterate(?StorageListOptions $options = null, ?int $chunkSize = null, int|float|string|null $timeoutSecs = null): Generator
    {
        $options ??= new StorageListOptions();
        return ResourceContext::paginateOffset(
            $options->offset ?? 0,
            $options->limit,
            $chunkSize,
            fn (int $offset, ?int $pageLimit) => $this->list($options->withPagination($offset, $pageLimit), $timeoutSecs),
        );
    }

    /** Returns the queue with the name, creating it if needed. An empty name creates an unnamed queue. */
    public function getOrCreate(?string $name = null, int|float|string|null $timeoutSecs = null): RequestQueue
    {
        return new RequestQueue($this->ctx->getOrCreateNamed($name, null, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)));
    }
}
