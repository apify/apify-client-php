<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\ActorVersion;

/**
 * A client for a specific Actor version ({@code GET/PUT/DELETE
 * /v2/actors/{actorId}/versions/{versionNumber}}).
 */
final class ActorVersionClient
{
    private ResourceContext $ctx;
    private string $versionUrl;

    /** @internal */
    public function __construct(private HttpClientCore $http, string $actorUrl, string $versionNumber)
    {
        $this->ctx = ResourceContext::single($http, $actorUrl, 'versions', $versionNumber);
        $this->versionUrl = $this->ctx->subUrl('');
    }

    /** Fetches the version, or {@code null} if it does not exist. */
    public function get(): ?ActorVersion
    {
        $data = $this->ctx->getResource('', new QueryParams());
        return is_array($data) ? new ActorVersion($data) : null;
    }

    /**
     * Updates the version with the given fields and returns the updated object.
     *
     * @param mixed $newFields any JSON-serializable set of fields to update
     */
    public function update(mixed $newFields): ActorVersion
    {
        return new ActorVersion($this->ctx->updateResource('', $newFields));
    }

    /** Deletes the version. */
    public function delete(): void
    {
        $this->ctx->deleteResource('');
    }

    /** A client for a specific environment variable of this version. */
    public function envVar(string $name): ActorEnvVarClient
    {
        return new ActorEnvVarClient($this->http, $this->versionUrl, $name);
    }

    /** A client for this version's environment variable collection. */
    public function envVars(): ActorEnvVarCollectionClient
    {
        return new ActorEnvVarCollectionClient($this->http, $this->versionUrl);
    }
}
