<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\ActorEnvVar;
use Apify\Client\Model\PaginationList;

/**
 * A client for an Actor version's environment variable collection ({@code GET/POST
 * /v2/actors/{actorId}/versions/{versionNumber}/env-vars}).
 */
final class ActorEnvVarCollectionClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $versionUrl)
    {
        $this->ctx = ResourceContext::collection($http, $versionUrl, 'env-vars');
    }

    /**
     * Lists the version's environment variables.
     *
     * @return PaginationList<ActorEnvVar>
     */
    public function list(): PaginationList
    {
        return $this->ctx->listResource('', new QueryParams(), static fn (array $d) => ActorEnvVar::fromArray($d));
    }

    /** Creates a new environment variable. */
    public function create(ActorEnvVar $envVar): ActorEnvVar
    {
        return ActorEnvVar::fromArray($this->ctx->createResource(new QueryParams(), $envVar->toArray()));
    }
}
