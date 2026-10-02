<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\Build;

/** A client for a specific Actor build ({@code /v2/actor-builds/{buildId}}). */
final class BuildClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(private HttpClientCore $http, string $baseUrl, string $id)
    {
        $this->ctx = ResourceContext::single($http, $baseUrl, 'actor-builds', $id);
    }

    /**
     * Fetches the build, optionally asking the API to wait up to {@code $waitForFinishSecs} seconds
     * for the build to finish before responding. The value is clamped client-side to the per-request
     * timeout budget (minus a safety margin) so the server is never asked to hold the connection
     * longer than the client will wait; the server additionally caps the wait at 60s. Returns
     * {@code null} if it does not exist.
     */
    public function get(?int $waitForFinishSecs = null, int|float|string|null $timeoutSecs = null): ?Build
    {
        $params = new QueryParams();
        // Clamp to the client's per-request timeout so a short custom timeout doesn't abort the call.
        $params->addInt('waitForFinish', $this->ctx->clampServerWait($waitForFinishSecs));
        $data = $this->ctx->getResource('', $params, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? new Build($data) : null;
    }

    /** Aborts the build and returns its updated state. */
    public function abort(int|float|string|null $timeoutSecs = null): Build
    {
        return new Build(
            $this->ctx->postWithBody('abort', new QueryParams(), null, '', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM))
        );
    }

    /** Deletes the build. */
    public function delete(int|float|string|null $timeoutSecs = null): void
    {
        $this->ctx->deleteResource('', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
    }

    /**
     * Polls until the build reaches a terminal state or {@code $waitSecs} elapses ({@code null} waits
     * indefinitely). Returns the latest build.
     */
    public function waitForFinish(?int $waitSecs = null): Build
    {
        $data = $this->ctx->waitForFinish(
            $waitSecs,
            'build',
            static fn (array $d): bool => (new Build($d))->isTerminal()
        );
        return new Build($data);
    }

    /**
     * Returns the OpenAPI definition generated for the build.
     *
     * Like the reference client, a 404 here is not swallowed: it always throws instead, since the only
     * way this fixed sub-path 404s is the build itself being gone (same rule as {@see
     * \Apify\Client\Resource\DatasetClient::getStatistics()} and friends). A {@code null} return means
     * the response body did not decode to an object — not a missing build, which throws.
     *
     * @return array<string,mixed>|null the raw OpenAPI document
     */
    public function getOpenApiDefinition(int|float|string|null $timeoutSecs = null): ?array
    {
        $response = $this->ctx->getRawRequired('openapi.json', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM));
        $decoded = Json::decode((string) $response->getBody());
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * A client for accessing this build's log.
     *
     * A 404 from this client's {@code get()} throws an {@see \Apify\Client\Exception\ApifyApiException}
     * instead of resolving to {@code null}, since this build client has no ID of its own at this
     * sub-path — the 404 could mean either the build or its log is gone, and the response cannot tell
     * the two apart (matches the reference client's {@code catchNotFoundForResourceOrThrow}).
     */
    public function log(): LogClient
    {
        return LogClient::nested($this->http, $this->ctx->subUrl(''));
    }
}
