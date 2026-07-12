<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\Build;
use Apify\Client\Model\PaginationList;
use Apify\Client\Options\ListOptions;
use Generator;

/**
 * A client for a build collection: the account-wide collection ({@code GET /v2/actor-builds}) or an
 * Actor's builds ({@code GET /v2/actors/{id}/builds}).
 */
final class BuildCollectionClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $baseUrl, string $resourcePath)
    {
        $this->ctx = ResourceContext::collection($http, $baseUrl, $resourcePath);
    }

    /**
     * Lists builds.
     *
     * @return PaginationList<Build>
     */
    public function list(?ListOptions $options = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new ListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new Build($d));
    }

    /**
     * Lazily iterates over builds, fetching pages on demand. The options' {@code limit} caps the total
     * number of builds yielded across all pages ({@code null} = all); {@code $chunkSize} is the
     * per-page size ({@code null} = the server default).
     *
     * @return Generator<int,Build>
     */
    public function iterate(?ListOptions $options = null, ?int $chunkSize = null): Generator
    {
        $options ??= new ListOptions();
        return ResourceContext::paginateOffset(
            $options->offset ?? 0,
            $options->limit,
            $chunkSize,
            fn (int $offset, ?int $pageLimit) => $this->list($options->withPagination($offset, $pageLimit)),
        );
    }
}
