<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Exception\ApifyClientException;
use Apify\Client\Internal\Compression;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\RequestPolicy;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\Actor;
use Apify\Client\Options\ActorServerRequestOptions;
use Apify\Client\Options\ListOptions;

/**
 * A client for the server interface of an Actor. A Server Actor runs a web server that answers HTTP requests
 * without a run per request. Get an instance with {@see ActorClient::server()}.
 *
 * @see https://docs.apify.com/platform/actors/running/server-actors
 */
final class ActorServerClient
{
    /**
     * The statuses a server request is retried on. The platform answers with them when it cannot pass the
     * request to a server run. An Actor that returns one itself gets the request again.
     */
    private const SERVER_RETRY_STATUS_CODES = [429, 503];

    /** The header that carries the ID of a request to a multi-tenant Server Actor. */
    private const REQUEST_ID_HEADER = 'X-Actor-Request-ID';

    /** The tag of the build that serves the server interface when the Actor does not name one. */
    private const DEFAULT_BUILD_REF = 'latest';

    /** A build number such as {@code 0.1.23}: version, minor version and sequence number. */
    private const BUILD_NUMBER_PATTERN = '/^(\d+)\.(\d+)\.(\d+)$/';

    private ResourceContext $ctx;

    /** The server URL, cached once the Actor has been fetched. */
    private ?string $url = null;

    /** @internal */
    public function __construct(
        private HttpClientCore $http,
        private string $baseUrl,
        private string $id,
    ) {
        $this->ctx = ResourceContext::single($http, $baseUrl, 'actors', $id);
    }

    /**
     * Returns the URL of the Actor's server, its {@code standbyUrl}. It is fetched by the first call of this
     * method or of {@see getOpenApiSpec()} and reused. Use it to call the server with another HTTP client.
     *
     * @param int|float|string|null $timeoutSecs timeout of the API request (default: the {@code short} tier)
     * @throws ApifyClientException if the Actor is not a Server Actor
     * @throws \Apify\Client\Exception\NotFoundException if the Actor does not exist
     */
    public function getUrl(int|float|string|null $timeoutSecs = null): string
    {
        return $this->url ?? $this->fetchServerActor($timeoutSecs)[1];
    }

    /**
     * Returns the OpenAPI specification of the Actor's server: the web server schema of the build that serves it,
     * which is {@code actorStandby.build} or {@code latest}. Point a generated HTTP client at {@see getUrl()}.
     *
     * @param int|float|string|null $timeoutSecs timeout of each API request (default: the {@code short} tier)
     * @return array<string,mixed>|null the specification, or {@code null} if the Actor's definition has none
     * @throws ApifyClientException if the Actor is not a Server Actor, or the build does not exist
     * @throws \Apify\Client\Exception\NotFoundException if the Actor does not exist
     */
    public function getOpenApiSpec(int|float|string|null $timeoutSecs = null): ?array
    {
        [$actor] = $this->fetchServerActor($timeoutSecs);
        $standby = $actor->get('actorStandby');
        $standbyBuild = is_array($standby) ? ($standby['build'] ?? null) : null;
        $buildRef = is_string($standbyBuild) && $standbyBuild !== '' ? $standbyBuild : self::DEFAULT_BUILD_REF;

        $buildId = $this->resolveBuildId($actor, $buildRef, $timeoutSecs);
        $build = $buildId === null ? null : (new BuildClient($this->http, $this->baseUrl, $buildId))->get(null, $timeoutSecs);
        if ($build === null) {
            throw new ApifyClientException(sprintf('Build %s of Actor %s does not exist.', $buildRef, $this->id));
        }

        $definition = $build->get('actorDefinition');
        $schema = is_array($definition) ? ($definition['webServerSchema'] ?? null) : null;
        return is_array($schema) ? $schema : null;
    }

    /**
     * Sends an HTTP request to the Actor's server.
     *
     * The token goes in the {@code X-Apify-Authorization} header, which the platform consumes, so it never
     * reaches the Actor. The headers you pass, {@code Authorization} included, reach it as they are. The first
     * request to an idle Actor waits for a server run to start, so the timeout defaults to the {@code long} tier.
     *
     * Any response of the server is returned, error statuses included. A 429 or 503 is retried with the client's
     * retry settings, and the last one is returned when they run out. Other failures are not retried, since the
     * Actor may have handled the request: a transport error, a timeout included, throws
     * {@see \Apify\Client\Exception\TransportException}. A redirect is returned as it is, so the token never
     * follows it to another host. The body is sent uncompressed unless the {@code compression} option is set,
     * since the server may not decode it.
     *
     * To call a Server Actor from a multi-tenant Server Actor, set {@see ActorServerRequestOptions::$parentRequestId}.
     *
     * @param string $path the path on the server, for example {@code /search}. It is resolved against the server
     *                     URL, so the request cannot go to another host. Pass the query in the {@code params}
     *                     option. A fragment is dropped.
     * @param int|float|string|null $timeoutSecs timeout of the HTTP request (default: the {@code long} tier).
     *                     With the {@code stream} option, it bounds the wait for the response to start.
     * @throws ApifyClientException if the Actor is not a Server Actor, or the HTTP client cannot disable
     *                              redirects (see {@see \Apify\Client\Http\NoRedirectHttpClientInterface})
     * @throws \Apify\Client\Exception\NotFoundException if the Actor does not exist
     * @throws \Apify\Client\Exception\TransportException if the request fails or times out
     * @throws \InvalidArgumentException if a query parameter is not a scalar or a list of scalars
     */
    public function request(string $path = '/', ?ActorServerRequestOptions $options = null, int|float|string|null $timeoutSecs = null): ActorServerResponse
    {
        $options ??= new ActorServerRequestOptions();
        $params = new QueryParams();
        $options->appendTo($params);
        [$body, $contentType] = self::encodeBody($options->body);
        $extraHeaders = self::withParentRequestId($options->headers ?? [], $options->parentRequestId);

        $policy = new RequestPolicy(
            compression: Compression::resolveCompressor($options->compression, 'ActorServerRequestOptions') ?? false,
            authorizationHeader: RequestPolicy::SERVER_AUTHORIZATION_HEADER,
            throwOnErrorStatus: false,
            retryStatusCodes: self::SERVER_RETRY_STATUS_CODES,
            retryTransportErrors: false,
            followRedirects: false,
            stream: $options->stream,
        );
        $timeout = $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG);

        $url = $params->applyToUrl(self::serverRequestUrl($this->getUrl(), $path));
        $response = $this->http->call($options->methodOrDefault(), $url, $body, $contentType, $timeout, extraHeaders: $extraHeaders, policy: $policy);
        return new ActorServerResponse($response);
    }

    /**
     * Fetches the Actor, checks that it is a Server Actor and caches its server URL.
     *
     * @return array{0:Actor,1:string} the Actor and its server URL
     * @throws ApifyClientException if the Actor is not a Server Actor
     */
    private function fetchServerActor(int|float|string|null $timeoutSecs): array
    {
        $data = $this->ctx->getResourceRequired('', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        $actor = new Actor(is_array($data) ? $data : []);
        $url = $actor->getStandbyUrl();
        if ($url === null || $url === '') {
            throw new ApifyClientException(sprintf('Actor %s is not a Server Actor.', $this->id));
        }
        $this->url = $url;
        return [$actor, $url];
    }

    /** Finds the ID of the build that a tag or build number names, or returns {@code null}. */
    private function resolveBuildId(Actor $actor, string $buildRef, int|float|string|null $timeoutSecs): ?string
    {
        $taggedBuilds = $actor->get('taggedBuilds');
        $tagged = is_array($taggedBuilds) ? ($taggedBuilds[$buildRef] ?? null) : null;
        $taggedId = is_array($tagged) ? ($tagged['buildId'] ?? null) : null;
        if (is_string($taggedId)) {
            return $taggedId;
        }

        $target = self::parseBuildNumber($buildRef);
        if ($target === null) {
            return null;
        }
        // The API cannot resolve a build number directly. Builds are listed newest first and sequence numbers
        // grow, so meeting an older build of the same version means the target does not exist.
        $builds = new BuildCollectionClient($this->http, $this->ctx->subUrl(''), 'builds');
        foreach ($builds->iterate(new ListOptions(desc: true), timeoutSecs: $timeoutSecs) as $build) {
            if ($build->getBuildNumber() === $buildRef) {
                return $build->getId();
            }
            $number = self::parseBuildNumber($build->getBuildNumber());
            if ($number !== null && $number[0] === $target[0] && $number[1] === $target[1] && $number[2] < $target[2]) {
                break;
            }
        }
        return null;
    }

    /**
     * Splits a build number such as {@code 0.1.23} into its parts, or returns {@code null}.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function parseBuildNumber(?string $buildNumber): ?array
    {
        if (preg_match(self::BUILD_NUMBER_PATTERN, $buildNumber ?? '', $match) !== 1) {
            return null;
        }
        return [(int) $match[1], (int) $match[2], (int) $match[3]];
    }

    /**
     * Sets the {@code X-Actor-Request-ID} header, replacing one in {@code $headers} whatever its casing.
     *
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private static function withParentRequestId(array $headers, ?string $parentRequestId): array
    {
        if ($parentRequestId === null) {
            return $headers;
        }
        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, self::REQUEST_ID_HEADER) === 0) {
                unset($headers[$name]);
            }
        }
        $headers[self::REQUEST_ID_HEADER] = $parentRequestId;
        return $headers;
    }

    /** Resolves {@code $path} against the server URL, so no path can address another host. */
    private static function serverRequestUrl(string $serverUrl, string $path): string
    {
        $withoutFragment = explode('#', $path, 2)[0];
        return rtrim($serverUrl, '/') . '/' . ltrim($withoutFragment, '/');
    }

    /**
     * Serializes a request body: a string goes out as it is, and any other value as JSON.
     *
     * @return array{0:?string,1:string} the body and its content type
     */
    private static function encodeBody(mixed $body): array
    {
        if ($body === null) {
            return [null, ''];
        }
        if (is_string($body)) {
            return [$body, ''];
        }
        return [Json::encode($body), ResourceContext::CONTENT_TYPE_JSON];
    }
}
