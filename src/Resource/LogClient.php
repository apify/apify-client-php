<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Options\LogOptions;
use Psr\Http\Message\StreamInterface;

/**
 * A client for accessing the log of an Actor build or run ({@code /v2/logs/{buildOrRunId}}, or the
 * run/build-nested {@code .../log}).
 */
final class LogClient
{
    private function __construct(private ResourceContext $ctx)
    {
    }

    /** @internal */
    public static function forId(HttpClientCore $http, string $baseUrl, string $id): self
    {
        return new self(ResourceContext::single($http, $baseUrl, 'logs', $id));
    }

    /**
     * Creates a log client for a run's or build's nested log endpoint (e.g. {@code .../log}). Any
     * {@code $inheritedParams} (e.g. the {@code status}/{@code origin} filters pinned by a last-run
     * accessor) become base params so the log of the correct run is resolved. @internal
     */
    public static function nested(HttpClientCore $http, string $base, ?QueryParams $inheritedParams = null): self
    {
        $ctx = ResourceContext::collection($http, $base, 'log');
        if ($inheritedParams !== null) {
            $ctx->baseParams = $inheritedParams->copy();
        }
        return new self($ctx);
    }

    /**
     * Fetches the log as text, or {@code null} if the log does not exist.
     *
     * Unlike the reference client, there is no separate {@code buffer} option: a PHP string is already
     * a byte sequence, never re-decoded, so this already returns the exact bytes the API sent — the
     * same thing the reference client's {@code buffer: true} opts into for its own string/Buffer split.
     *
     * When this client was obtained without an ID (e.g. {@see \Apify\Client\Resource\RunClient::log()} or
     * {@see \Apify\Client\Resource\BuildClient::log()}), a 404 is rethrown instead, since it could mean
     * either the parent resource or the log is gone.
     */
    public function get(?LogOptions $options = null, int|float|string|null $timeoutSecs = null): ?string
    {
        $params = new QueryParams();
        ($options ?? new LogOptions())->appendTo($params);
        $response = $this->ctx->getRaw('', $params, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG));
        return $response === null ? null : (string) $response->getBody();
    }

    /**
     * Opens a live, streaming connection to the log and returns a stream over the log bytes.
     *
     * Unlike {@see get()}, this bypasses the buffered/retrying transport so the log can be followed
     * in real time as the run produces it (the {@code stream=1} query parameter). Because the
     * response is consumed incrementally, it is not retried.
     */
    public function stream(?LogOptions $options = null): StreamInterface
    {
        $params = new QueryParams();
        $params->addBool('stream', true);
        ($options ?? new LogOptions())->appendTo($params);
        return $this->ctx->streamRaw('', $params);
    }
}
