<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
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
    public function list(?StorageListOptions $options = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new StorageListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new RequestQueue($d));
    }

    /**
     * Lazily iterates over request queues, fetching pages on demand. The options' {@code limit} caps
     * the total number of queues yielded across all pages ({@code null} = all); {@code $chunkSize} is
     * the per-page size ({@code null} = the server default).
     *
     * @return Generator<int,RequestQueue>
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
     * Gets the queue with the given name, creating it if it does not exist. An empty/{@code null}
     * name creates a new unnamed queue.
     */
    public function getOrCreate(?string $name = null): RequestQueue
    {
        return new RequestQueue($this->ctx->getOrCreateNamed($name));
    }
}
