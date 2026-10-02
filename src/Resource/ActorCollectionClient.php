<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\Actor;
use Apify\Client\Model\PaginationList;
use Apify\Client\Options\ActorListOptions;
use Generator;

/** A client for the Actor collection ({@code GET/POST /v2/actors}). */
final class ActorCollectionClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $baseUrl)
    {
        $this->ctx = ResourceContext::collection($http, $baseUrl, 'actors');
    }

    /**
     * Lists the account's Actors.
     *
     * @return PaginationList<Actor>
     */
    public function list(?ActorListOptions $options = null, int|float|string|null $timeoutSecs = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new ActorListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new Actor($d), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM));
    }

    /**
     * Lazily iterates over the account's Actors, fetching pages on demand. The options' {@code limit}
     * caps the total number of Actors yielded across all pages ({@code null} = all); {@code $chunkSize}
     * is the per-page size ({@code null} = the server default).
     *
     * @return Generator<int,Actor>
     */
    public function iterate(?ActorListOptions $options = null, ?int $chunkSize = null, int|float|string|null $timeoutSecs = null): Generator
    {
        $options ??= new ActorListOptions();
        return ResourceContext::paginateOffset(
            $options->offset ?? 0,
            $options->limit,
            $chunkSize,
            fn (int $offset, ?int $pageLimit) => $this->list($options->withPagination($offset, $pageLimit), $timeoutSecs),
        );
    }

    /**
     * Creates a new Actor.
     *
     * @param mixed $actor any JSON-serializable Actor definition
     */
    public function create(mixed $actor, int|float|string|null $timeoutSecs = null): Actor
    {
        return new Actor($this->ctx->createResource(new QueryParams(), $actor, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM)));
    }
}
