<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\ActorRun;
use Apify\Client\Model\PaginationList;
use Apify\Client\Options\ListOptions;
use Apify\Client\Options\RunListOptions;
use Generator;

/** A client for a run collection: all runs of the account, or the runs of an Actor or task. */
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
    public function list(?ListOptions $options = null, ?RunListOptions $filter = null, int|float|string|null $timeoutSecs = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new ListOptions())->appendTo($params);
        ($filter ?? new RunListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new ActorRun($d), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM));
    }

    /**
     * Lazily yields runs, with the filters applied to every page. The {@code limit} option caps the total
     * ({@code null} for all) and {@code $chunkSize} sets the page size ({@code null} for the API's default).
     *
     * @return Generator<int,ActorRun>
     */
    public function iterate(?ListOptions $options = null, ?RunListOptions $filter = null, ?int $chunkSize = null, int|float|string|null $timeoutSecs = null): Generator
    {
        $options ??= new ListOptions();
        return ResourceContext::paginateOffset(
            $options->offset ?? 0,
            $options->limit,
            $chunkSize,
            fn (int $offset, ?int $pageLimit) => $this->list($options->withPagination($offset, $pageLimit), $filter, $timeoutSecs),
        );
    }
}
