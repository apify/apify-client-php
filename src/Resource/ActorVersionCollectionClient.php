<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\ActorVersion;
use Apify\Client\Model\PaginationList;
use Generator;

/** A client for an Actor's version collection ({@code GET/POST /v2/actors/{actorId}/versions}). */
final class ActorVersionCollectionClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $actorUrl)
    {
        $this->ctx = ResourceContext::collection($http, $actorUrl, 'versions');
    }

    /**
     * Lists the Actor's versions. The endpoint takes no filtering or pagination parameters: it always
     * returns every version in one response.
     *
     * @return PaginationList<ActorVersion>
     */
    public function list(int|float|string|null $timeoutSecs = null): PaginationList
    {
        return $this->ctx->listResource('', new QueryParams(), static fn (array $d) => new ActorVersion($d), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM));
    }

    /**
     * Lazily iterates over the Actor's versions.
     *
     * This endpoint ignores {@code offset}/{@code limit} and always returns every version in one
     * response, so iteration makes exactly one request — unlike the offset/limit collection iterators,
     * there is no per-page {@code chunkSize} to request fewer at a time, and a generic paginating loop
     * would re-request (and re-yield) the same full list forever.
     *
     * @return Generator<int,ActorVersion>
     */
    public function iterate(int|float|string|null $timeoutSecs = null): Generator
    {
        yield from $this->list($timeoutSecs)->getItems();
    }

    /**
     * Creates a new Actor version.
     *
     * @param mixed $version any JSON-serializable version definition
     */
    public function create(mixed $version, int|float|string|null $timeoutSecs = null): ActorVersion
    {
        return new ActorVersion($this->ctx->createResource(new QueryParams(), $version, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM)));
    }
}
