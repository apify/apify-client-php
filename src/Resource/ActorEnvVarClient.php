<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\ActorEnvVar;

/**
 * A client for a single environment variable ({@code GET/PUT/DELETE
 * /v2/actors/{actorId}/versions/{versionNumber}/env-vars/{name}}).
 */
final class ActorEnvVarClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $versionUrl, string $name)
    {
        $this->ctx = ResourceContext::single($http, $versionUrl, 'env-vars', $name);
    }

    /** Fetches the environment variable, or {@code null} if it does not exist. */
    public function get(int|float|string|null $timeoutSecs = null): ?ActorEnvVar
    {
        $data = $this->ctx->getResource('', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? ActorEnvVar::fromArray($data) : null;
    }

    /** Updates the environment variable and returns the updated object. */
    public function update(ActorEnvVar $envVar, int|float|string|null $timeoutSecs = null): ActorEnvVar
    {
        return ActorEnvVar::fromArray(
            $this->ctx->updateResource('', $envVar->toArray(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT))
        );
    }

    /** Deletes the environment variable. */
    public function delete(int|float|string|null $timeoutSecs = null): void
    {
        $this->ctx->deleteResource('', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
    }
}
