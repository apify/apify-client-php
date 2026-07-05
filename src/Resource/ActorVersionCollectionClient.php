<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\ActorVersion;
use Apify\Client\Model\PaginationList;
use Apify\Client\Options\ListOptions;

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
     * Lists the Actor's versions.
     *
     * @return PaginationList<ActorVersion>
     */
    public function list(?ListOptions $options = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new ListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new ActorVersion($d));
    }

    /**
     * Creates a new Actor version.
     *
     * @param mixed $version any JSON-serializable version definition
     */
    public function create(mixed $version): ActorVersion
    {
        return new ActorVersion($this->ctx->createResource(new QueryParams(), $version));
    }
}
