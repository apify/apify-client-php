<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\ActorRun;
use Apify\Client\Model\PaginationList;
use Apify\Client\Options\ListOptions;
use Apify\Client\Options\RunListOptions;
use Generator;

/**
 * A client for a run collection: the account-wide collection ({@code GET /v2/actor-runs}), an
 * Actor's runs ({@code GET /v2/actors/{id}/runs}), or a task's runs ({@code GET
 * /v2/actor-tasks/{id}/runs}).
 */
final class RunCollectionClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $baseUrl, string $resourcePath)
    {
        $this->ctx = ResourceContext::collection($http, $baseUrl, $resourcePath);
    }

    /**
     * Lists runs, applying the standard pagination and the run-specific filters.
     *
     * @return PaginationList<ActorRun>
     */
    public function list(?ListOptions $options = null, ?RunListOptions $filter = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new ListOptions())->appendTo($params);
        ($filter ?? new RunListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new ActorRun($d));
    }

    /**
     * Lazily iterates over runs, fetching pages on demand and applying the run-specific filters to
     * every page. The options' {@code limit} caps the total number of runs yielded across all pages
     * ({@code null} = all); {@code $chunkSize} is the per-page size ({@code null} = the server default).
     *
     * @return Generator<int,ActorRun>
     */
    public function iterate(?ListOptions $options = null, ?RunListOptions $filter = null, ?int $chunkSize = null): Generator
    {
        $options ??= new ListOptions();
        return ResourceContext::paginateOffset(
            $options->offset ?? 0,
            $options->limit,
            $chunkSize,
            fn (int $offset, ?int $pageLimit) => $this->list($options->withPagination($offset, $pageLimit), $filter),
        );
    }
}
