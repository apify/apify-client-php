<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\ActorEnvVar;
use Apify\Client\Model\PaginationList;
use Generator;

/** A client for the environment variables of an Actor version. */
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
    public function list(int|float|string|null $timeoutSecs = null): PaginationList
    {
        return $this->ctx->listResource(
            '',
            new QueryParams(),
            static fn (array $d) => ActorEnvVar::fromArray($d),
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)
        );
    }

    /**
     * Lazily yields the environment variables of the version. The endpoint returns all of them in one response,
     * so this makes one request.
     *
     * @return Generator<int,ActorEnvVar>
     */
    public function iterate(int|float|string|null $timeoutSecs = null): Generator
    {
        yield from $this->list($timeoutSecs)->getItems();
    }

    /** Creates a new environment variable. */
    public function create(ActorEnvVar $envVar, int|float|string|null $timeoutSecs = null): ActorEnvVar
    {
        return ActorEnvVar::fromArray(
            $this->ctx->createResource(new QueryParams(), $envVar->toArray(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT))
        );
    }
}
