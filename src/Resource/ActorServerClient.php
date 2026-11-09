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
 * A client for the server interface of a specific Actor.
 *
 * A Server Actor (formerly Actor Standby) runs a web server, its server interface, that is called over HTTP
 * without starting a run for each call. This client sends requests to that server and reads its URL and
 * OpenAPI specification. Get an instance with {@see ActorClient::server()}.
 *
 * @see https://docs.apify.com/platform/actors/running/standby
 */
final class ActorServerClient
{
    /**
     * The statuses a server request is retried on. The platform answers with them when it cannot pass a request
     * to a server run, so a retry usually does not run the Actor's code again. An Actor that returns one itself
     * gets the request again.
     */
    private const SERVER_RETRY_STATUS_CODES = [429, 503];

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
     * Returns the URL of the Actor's server.
     *
     * The URL is the Actor's {@code standbyUrl}, fetched from the API by the first call of this method or of
     * {@see getOpenApiSpec()} and reused by later calls on this client. Use it to call the server with another
     * HTTP client, for example one generated from the server's OpenAPI specification.
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
     * Returns the OpenAPI specification of the Actor's server.
     *
     * The specification is the web server schema from the Actor's definition, taken from the build that serves
     * the server interface: the build set in {@code actorStandby.build}, or {@code latest} if none is set. Use it
     * to generate an HTTP client for the server, and point that client at the URL returned by {@see getUrl()}.
     *
     * @param int|float|string|null $timeoutSecs timeout of each API request (default: the {@code short} tier)
     * @return array<string,mixed>|null the specification, or {@code null} if the Actor's definition does not
     *                                  include one
     * @throws ApifyClientException if the Actor is not a Server Actor, or the build that serves its server
     *                              interface does not exist
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
     * The request goes to the URL returned by {@see getUrl()} and carries the client's token in the
     * {@code X-Apify-Authorization} header, which the platform consumes, so it never reaches the Actor's code.
     * Headers you pass, {@code Authorization} included, reach the Actor's server as they are. The first request
     * to an idle Actor waits for the platform to start a server run, which is why the timeout defaults to the
     * {@code long} tier.
     *
     * The method returns any response of the server, error statuses included. A rate-limit (429) or Service
     * Unavailable (503) response is retried with the client's retry settings, and the last one is returned once
     * the retries run out. The Actor's code can return these statuses too, and then it gets the request again.
     * Other failures are not retried, since the Actor may already have handled the request: a transport error,
     * a timeout included, throws {@see \Apify\Client\Exception\TransportException} right away. A redirect is
     * returned as it is, since following it would send the token to a host the Actor's code picked. Request
     * bodies are sent uncompressed unless the {@code compression} option is set, since the Actor's server may
     * not decode a compressed body.
     *
     * @param string $path the path on the Actor's server, for example {@code /search}. It is always resolved
     *                     against the server URL, so the request, and the token with it, cannot be sent to
     *                     another host. Pass the query in the {@code params} option. A fragment
     *                     ({@code #...}) is dropped, since it is never sent to the server.
     * @param int|float|string|null $timeoutSecs timeout of the HTTP request (default: the {@code long} tier).
     *                     With the {@code stream} option, it bounds the wait for the server to start responding.
     * @throws ApifyClientException if the Actor is not a Server Actor, or the HTTP client in use cannot disable
     *                              redirects (see {@see \Apify\Client\Http\NoRedirectHttpClientInterface})
     * @throws \Apify\Client\Exception\NotFoundException if the Actor does not exist
     * @throws \Apify\Client\Exception\TransportException if the request fails or times out before the server
     *                              responds
     * @throws \InvalidArgumentException if a query parameter is not a scalar or a list of scalars
     */
    public function request(string $path = '/', ?ActorServerRequestOptions $options = null, int|float|string|null $timeoutSecs = null): ActorServerResponse
    {
        $options ??= new ActorServerRequestOptions();
        $params = new QueryParams();
        $options->appendTo($params);
        [$body, $contentType] = self::encodeBody($options->body);
        $extraHeaders = $options->headers ?? [];

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
     * Fetches the Actor, checks that it is a Server Actor, and caches its server URL.
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

    /**
     * Finds the ID of the build a tag or build number refers to, or {@code null} if there is none.
     */
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
        // Not a tag, so look `$buildRef` up as a build number, which the API cannot resolve directly. Builds are
        // listed newest first and the sequence numbers of a version grow over time, so meeting an older build of
        // the same version means the target does not exist.
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
     * Splits a build number like {@code 0.1.23} into its parts, or returns {@code null} for anything else.
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
