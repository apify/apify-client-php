<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
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
    public function get(): ?ActorEnvVar
    {
        $data = $this->ctx->getResource('', new QueryParams());
        return is_array($data) ? ActorEnvVar::fromArray($data) : null;
    }

    /** Updates the environment variable and returns the updated object. */
    public function update(ActorEnvVar $envVar): ActorEnvVar
    {
        return ActorEnvVar::fromArray($this->ctx->updateResource('', $envVar->toArray()));
    }

    /** Deletes the environment variable. */
    public function delete(): void
    {
        $this->ctx->deleteResource('');
    }
}
