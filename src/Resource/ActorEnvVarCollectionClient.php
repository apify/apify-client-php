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
    public function list(int|float|string|null $timeoutSecs = null): PaginationList
    {
        return $this->ctx->listResource(
            '',
            new QueryParams(),
            static fn (array $d) => ActorEnvVar::fromArray($d),
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM)
        );
    }

    /**
     * Lazily iterates over the version's environment variables, fetching pages on demand.
     * {@code $chunkSize} caps the per-page size ({@code null} = the server default). This endpoint is
     * not filtered, so iteration mirrors the reference client's parameterless {@code list()} iterator.
     *
     * @return Generator<int,ActorEnvVar>
     */
    public function iterate(?int $chunkSize = null, int|float|string|null $timeoutSecs = null): Generator
    {
        return ResourceContext::paginateOffset(
            0,
            null,
            $chunkSize,
            function (int $offset, ?int $pageLimit) use ($timeoutSecs) {
                $params = new QueryParams();
                $params->addInt('offset', $offset)->addInt('limit', $pageLimit);
                return $this->ctx->listResource('', $params, static fn (array $d) => ActorEnvVar::fromArray($d), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM));
            },
        );
    }

    /** Creates a new environment variable. */
    public function create(ActorEnvVar $envVar, int|float|string|null $timeoutSecs = null): ActorEnvVar
    {
        return ActorEnvVar::fromArray(
            $this->ctx->createResource(new QueryParams(), $envVar->toArray(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM))
        );
    }
}
