<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Options\LogOptions;
use Psr\Http\Message\StreamInterface;

/** A client for the log of a build or run. */
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
     * A client for the nested log endpoint of a build or run. The {@code $inheritedParams}, such as the status
     * filter of a last-run accessor, are sent with every request.
     *
     * @internal
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
     * Fetches the log as text, or returns {@code null} if it does not exist. A client without an ID, such as
     * {@see \Apify\Client\Resource\RunClient::log()} or {@see \Apify\Client\Resource\BuildClient::log()},
     * rethrows the 404.
     */
    public function get(?LogOptions $options = null, int|float|string|null $timeoutSecs = null): ?string
    {
        $params = new QueryParams();
        ($options ?? new LogOptions())->appendTo($params);
        $response = $this->ctx->getRaw('', $params, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG));
        return $response === null ? null : (string) $response->getBody();
    }

    /** Opens a stream of the log, to follow it while the run produces it. The stream is not retried. */
    public function stream(?LogOptions $options = null): StreamInterface
    {
        $params = new QueryParams();
        $params->addBool('stream', true);
        ($options ?? new LogOptions())->appendTo($params);
        return $this->ctx->streamRaw('', $params);
    }
}
