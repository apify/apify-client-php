<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Options\LogOptions;
use Psr\Http\Message\StreamInterface;

/**
 * A client for accessing the log of an Actor build or run ({@code /v2/logs/{buildOrRunId}}, or the
 * run/build-nested {@code .../log}).
 */
final class LogClient
{
    private function __construct(private HttpClientCore $http, private ResourceContext $ctx)
    {
    }

    /** @internal */
    public static function forId(HttpClientCore $http, string $baseUrl, string $id): self
    {
        return new self($http, ResourceContext::single($http, $baseUrl, 'logs', $id));
    }

    /** Creates a log client for a run's or build's nested log endpoint (e.g. {@code .../log}). @internal */
    public static function nested(HttpClientCore $http, string $base): self
    {
        return new self($http, ResourceContext::collection($http, $base, 'log'));
    }

    /** Fetches the log as text, or {@code null} if the log does not exist. */
    public function get(?LogOptions $options = null): ?string
    {
        $params = new QueryParams();
        ($options ?? new LogOptions())->appendTo($params);
        $response = $this->ctx->getRaw('', $params);
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
        $url = $this->ctx->mergedParams($params)->applyToUrl($this->ctx->subUrl(''));

        $response = $this->http->stream($url);
        if ($response->getStatusCode() >= HttpClientCore::MAX_SUCCESS_STATUS) {
            $body = (string) $response->getBody();
            throw HttpClientCore::buildApiError(
                $response->getStatusCode(),
                $body,
                1,
                'GET',
                HttpClientCore::extractPath($url)
            );
        }
        return $response->getBody();
    }
}
