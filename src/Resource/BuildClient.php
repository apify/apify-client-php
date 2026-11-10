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
     * Fetches the build, or returns {@code null} if it does not exist. With {@code $waitForFinishSecs}, the API
     * holds the response until the build finishes, for at most 60 seconds and less than the request timeout. The
     * request timeout then defaults to that wait plus the {@code short} tier.
     */
    public function get(?int $waitForFinishSecs = null, int|float|string|null $timeoutSecs = null): ?Build
    {
        $params = new QueryParams();
        // Keep the server-side wait below the cap of a single attempt, so the API never holds longer than a request can last.
        $params->addInt('waitForFinish', $this->ctx->clampServerWait($waitForFinishSecs));
        $data = $this->ctx->getResource('', $params, $this->ctx->resolveWaitTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT, $waitForFinishSecs));
        return is_array($data) ? new Build($data) : null;
    }

    /** Aborts the build and returns its updated state. */
    public function abort(int|float|string|null $timeoutSecs = null): Build
    {
        return new Build(
            $this->ctx->postWithBody('abort', new QueryParams(), null, '', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT))
        );
    }

    /** Deletes the build. */
    public function delete(int|float|string|null $timeoutSecs = null): void
    {
        $this->ctx->deleteResource('', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
    }

    /**
     * Polls until the build finishes or {@code $waitSecs} elapse ({@code null} waits indefinitely), and returns
     * the latest build. {@code $timeoutSecs} is the timeout of each poll (default {@code 'noTimeout'}).
     */
    public function waitForFinish(?int $waitSecs = null, int|float|string|null $timeoutSecs = null): Build
    {
        $data = $this->ctx->waitForFinish(
            $waitSecs,
            'build',
            static fn (array $d): bool => (new Build($d))->isTerminal(),
            $timeoutSecs
        );
        return new Build($data);
    }

    /**
     * Returns the OpenAPI definition generated for the build. A 404 is always thrown, never turned into
     * {@code null}. A {@code null} result means the body is not an object.
     *
     * @return array<string,mixed>|null the OpenAPI document
     */
    public function getOpenApiDefinition(int|float|string|null $timeoutSecs = null): ?array
    {
        $response = $this->ctx->getRawRequired('openapi.json', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM));
        $decoded = Json::decode((string) $response->getBody());
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * A client for the log of the build.
     *
     * A 404 from its {@code get()} is thrown instead of returning {@code null}, since the client cannot tell a
     * missing build from a missing log.
     */
    public function log(): LogClient
    {
        return LogClient::nested($this->http, $this->ctx->subUrl(''));
    }
}
