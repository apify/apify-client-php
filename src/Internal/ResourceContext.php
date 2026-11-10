<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Model\PaginationList;
use Generator;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * The URL and HTTP client of a resource client, with the CRUD calls they share.
 *
 * @internal
 */
final class ResourceContext
{
    public const CONTENT_TYPE_JSON = 'application/json';
    public const CONTENT_TYPE_JSON_CHARSET = 'application/json; charset=utf-8';

    /** Seconds between polls while waiting for a run or build to finish. */
    private const WAIT_POLL_INTERVAL_SECS = 0.25;

    /** Seconds of one {@code waitForFinish} request. The API holds a response for at most 60. */
    private const WAIT_REQUEST_SECS = 60;

    /**
     * The most that is subtracted from the request timeout to get the {@code waitForFinish} value, so the API
     * answers before the socket times out. Never more than half the timeout.
     */
    private const WAIT_TIMEOUT_MARGIN_SECS = 5;

    /** Stands in for waiting indefinitely, which the API cannot express. About 11.5 days. */
    private const MAX_WAIT_FOR_FINISH_SECS = 999999;

    /**
     * How long {@see waitForFinish()} keeps polling a job that returns 404, to ride out replica lag. A
     * successful poll resets it.
     */
    private const MAX_WAIT_WHEN_JOB_NOT_FOUND_MILLIS = 3000;

    public QueryParams $baseParams;

    private string $apiOrigin;
    private string $publicOrigin;

    /** A timeout for every call of this context, set per resource client. {@code null} uses the client-wide one. */
    private ?float $requestTimeoutSecs = null;

    private function __construct(
        public HttpClientCore $http,
        /** The URL of the resource, for example {@code https://api.apify.com/v2/actors/ID}. */
        public string $url,
        string $baseUrl,
        /**
         * Whether the resource is addressed by an ID ({@see single()}) or by a fixed sub-path ({@see collection()}).
         * A 404 on a missing ID-addressed resource resolves to {@code null}, but a 404 on a sub-path resource
         * is rethrown, since the parent may be the one that is gone.
         */
        private bool $hasId = true,
    ) {
        $this->baseParams = new QueryParams();
        $this->apiOrigin = self::originOf($baseUrl);
        $this->publicOrigin = $this->apiOrigin;
    }

    /** Sets the timeout of every call of this context. */
    public function withTimeout(?float $timeoutSecs): self
    {
        $this->requestTimeoutSecs = $timeoutSecs;
        return $this;
    }

    /** A context for a collection or sub-path: {@code {base}/{resourcePath}}. */
    public static function collection(HttpClientCore $http, string $baseUrl, string $resourcePath): self
    {
        return new self($http, $baseUrl . '/' . $resourcePath, $baseUrl, hasId: false);
    }

    /** A context for a resource: {@code {base}/{resourcePath}/{id}}. */
    public static function single(HttpClientCore $http, string $baseUrl, string $resourcePath, string $id): self
    {
        $segment = self::encodePathSegment(self::toSafeId($id));
        return new self($http, $baseUrl . '/' . $resourcePath . '/' . $segment, $baseUrl, hasId: true);
    }

    /** Sets the origin of public URLs. */
    public function withPublicOrigin(string $publicBaseUrl): self
    {
        $this->publicOrigin = self::originOf($publicBaseUrl);
        return $this;
    }

    /** The URL of the resource, with an optional sub-path. */
    public function subUrl(string $subPath = ''): string
    {
        return $subPath === '' ? $this->url : $this->url . '/' . $subPath;
    }

    /** The shareable URL of the resource, with the public origin in place of the API one. */
    public function publicUrl(string $subPath): string
    {
        $apiUrl = $this->subUrl($subPath);
        if ($this->publicOrigin === $this->apiOrigin) {
            return $apiUrl;
        }
        if (str_starts_with($apiUrl, $this->apiOrigin)) {
            return $this->publicOrigin . substr($apiUrl, strlen($this->apiOrigin));
        }
        return $apiUrl;
    }

    /** The base params extended with the params of the call. */
    public function mergedParams(?QueryParams $params): QueryParams
    {
        return $this->baseParams->copy()->extend($params);
    }

    /** GETs a resource and returns its decoded {@code data}, or {@code null} if it is not found. */
    public function getResource(string $subPath, QueryParams $params, ?float $timeoutSecs = null): mixed
    {
        try {
            return $this->getResourceRequired($subPath, $params, $timeoutSecs);
        } catch (ApifyApiException $e) {
            if (HttpClientCore::isNotFound($e) && $this->swallowsNotFound($subPath)) {
                return null;
            }
            throw $e;
        }
    }

    /** GETs a resource and returns its decoded {@code data}. Any error is thrown. */
    public function getResourceRequired(string $subPath, QueryParams $params, ?float $timeoutSecs = null): mixed
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        $response = $this->http->call('GET', $url, timeoutSecs: $this->effectiveTimeout($timeoutSecs));
        return Json::decodeData((string) $response->getBody());
    }

    /**
     * PUTs a JSON body and returns the decoded {@code data}.
     *
     * @return array<string,mixed>
     */
    public function updateResource(string $subPath, mixed $body, ?float $timeoutSecs = null): array
    {
        $url = $this->mergedParams(new QueryParams())->applyToUrl($this->subUrl($subPath));
        $response = $this->http->call('PUT', $url, Json::encode($body), self::CONTENT_TYPE_JSON, timeoutSecs: $this->effectiveTimeout($timeoutSecs));
        return self::asArray(Json::decodeData((string) $response->getBody()));
    }

    /** DELETEs a resource. A 404 is ignored when the resource is addressed by an ID. */
    public function deleteResource(string $subPath, ?float $timeoutSecs = null): void
    {
        $url = $this->mergedParams(new QueryParams())->applyToUrl($this->subUrl($subPath));
        try {
            $this->http->call('DELETE', $url, timeoutSecs: $this->effectiveTimeout($timeoutSecs));
        } catch (ApifyApiException $e) {
            if (!HttpClientCore::isNotFound($e) || !$this->swallowsNotFound($subPath)) {
                throw $e;
            }
        }
    }

    /** The timeout of the call, else the one of this context. */
    private function effectiveTimeout(?float $timeoutSecs): ?float
    {
        return $timeoutSecs ?? $this->requestTimeoutSecs;
    }

    /**
     * Resolves a per-call {@code $timeoutSecs} (seconds, a tier name, {@see TimeoutTiers::NO_TIMEOUT} or
     * {@code null} for the default tier) to seconds. A timeout set on this context caps the result, even
     * a {@code 'noTimeout'} one.
     *
     * @param int|float|string|null $timeoutSecs
     */
    public function resolveTimeout(int|float|string|null $timeoutSecs, string $defaultTier): float
    {
        $resolved = $this->http->timeoutTiers()->resolve($timeoutSecs, $defaultTier);
        if ($this->requestTimeoutSecs === null) {
            return $resolved;
        }
        $comparable = $resolved === TimeoutTiers::INFINITE_SECS ? INF : $resolved;
        $capped = min($comparable, $this->requestTimeoutSecs);
        return $capped === INF ? TimeoutTiers::INFINITE_SECS : $capped;
    }

    /**
     * The timeout of a request that asks the API to hold its response with {@code waitForFinish}: the hold
     * (at most {@see WAIT_REQUEST_SECS}) plus the timeout of the tier. A request that is still being held is
     * then not aborted and retried, which would create a second resource for {@code start()} and
     * {@code build()}. An explicit {@code $timeoutSecs} is used as given.
     *
     * @param int|float|string|null $timeoutSecs
     */
    public function resolveWaitTimeout(int|float|string|null $timeoutSecs, string $defaultTier, ?int $waitForFinishSecs): float
    {
        if ($timeoutSecs !== null || $waitForFinishSecs === null) {
            return $this->resolveTimeout($timeoutSecs, $defaultTier);
        }
        $holdSecs = min($waitForFinishSecs, self::WAIT_REQUEST_SECS);
        if ($holdSecs <= 0) {
            return $this->resolveTimeout(null, $defaultTier);
        }
        $tierSecs = $this->http->timeoutTiers()->secondsFor($defaultTier);
        return $this->resolveTimeout($holdSecs + $tierSecs, $defaultTier);
    }

    /**
     * GETs a paginated listing as a {@see PaginationList} of hydrated items.
     *
     * @template T
     * @param callable(array<string,mixed>):T $hydrate
     * @return PaginationList<T>
     */
    public function listResource(string $subPath, QueryParams $params, callable $hydrate, ?float $timeoutSecs = null): PaginationList
    {
        $data = $this->getResourceRequired($subPath, $params, $timeoutSecs);
        return PaginationList::fromData($data, $hydrate);
    }

    /**
     * Lazily yields the items of an offset-paginated listing, fetching pages on demand.
     *
     * {@code $limit} caps the total number of items ({@code null} for all), {@code $chunkSize} the items per
     * page ({@code null} for the API's default), and {@code $startOffset} is the offset of the first page.
     *
     * The offset advances by the page's count, or by its items when there are more, capped at the limit asked
     * for. The listing's {@code total} is not used, since it can grow during the iteration. Entries the API
     * counted and then filtered out are therefore neither repeated nor cut the iteration short. Without
     * {@code $limit}, the last request advances by nothing.
     *
     * @template T
     * @param callable(int,?int):PaginationList<T> $fetchPage receives (offset, pageLimit) and returns that page
     * @return Generator<int,T>
     */
    public static function paginateOffset(int $startOffset, ?int $limit, ?int $chunkSize, callable $fetchPage): Generator
    {
        $currentOffset = $startOffset;
        // Items still to yield, or null without a $limit.
        $remaining = ($limit !== null && $limit > 0) ? $limit : null;
        do {
            // A null or 0 bound is unbounded, so the other one wins.
            $pageLimit = self::minLimit($remaining, $chunkSize);
            $page = $fetchPage($currentOffset, $pageLimit);
            foreach ($page->getItems() as $item) {
                yield $item;
            }
            $advance = self::advancedBy($page, $pageLimit);
            $currentOffset += $advance;
            if ($remaining !== null) {
                $remaining -= $advance;
            }
            // Stops when a page advances by nothing (the listing is exhausted) or the $limit is reached.
        } while ($advance > 0 && ($remaining === null || $remaining > 0));
    }

    /**
     * How far a page advances the offset: its count or its items, whichever is larger, capped at the limit asked
     * for ({@code null} for no cap).
     *
     * The count exceeds the items when the API drops entries after counting them (Store Actors, the dataset
     * {@code skipEmpty} and {@code clean} filters). The items exceed the count with {@code unwind}. The cap
     * keeps inflated items from skipping entries.
     *
     * @template T
     * @param PaginationList<T> $page
     */
    private static function advancedBy(PaginationList $page, ?int $requestedLimit): int
    {
        $advance = max($page->getCount(), count($page->getItems()));
        return $requestedLimit !== null ? min($advance, $requestedLimit) : $advance;
    }

    /**
     * The smaller of two bounds. {@code null} and {@code 0} are unbounded, as the API treats {@code limit=0} as
     * unset.
     *
     * @internal
     */
    public static function minLimit(?int $a, ?int $b): ?int
    {
        if ($a === 0) {
            $a = null;
        }
        if ($b === 0) {
            $b = null;
        }
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        return min($a, $b);
    }

    /**
     * POSTs a JSON body to create a resource and returns the decoded {@code data}.
     *
     * @return array<string,mixed>
     */
    public function createResource(QueryParams $params, mixed $body, ?float $timeoutSecs = null): array
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl(''));
        $response = $this->http->call('POST', $url, Json::encode($body), self::CONTENT_TYPE_JSON, timeoutSecs: $this->effectiveTimeout($timeoutSecs));
        return self::asArray(Json::decodeData((string) $response->getBody()));
    }

    /**
     * POSTs to get or create a named resource, with an optional schema in the body.
     *
     * @param array<string,mixed>|null $schema
     * @return array<string,mixed>
     */
    public function getOrCreateNamed(?string $name, ?array $schema = null, ?float $timeoutSecs = null): array
    {
        $params = new QueryParams();
        if ($name !== null && $name !== '') {
            $params->addString('name', $name);
        }
        $url = $params->applyToUrl($this->subUrl(''));
        $effectiveTimeout = $this->effectiveTimeout($timeoutSecs);
        $response = $schema !== null
            ? $this->http->call('POST', $url, Json::encode(['schema' => $schema]), self::CONTENT_TYPE_JSON, timeoutSecs: $effectiveTimeout)
            : $this->http->call('POST', $url, timeoutSecs: $effectiveTimeout);
        return self::asArray(Json::decodeData((string) $response->getBody()));
    }

    /**
     * POSTs a raw body and returns the decoded {@code data}.
     *
     * @return array<string,mixed>
     */
    public function postWithBody(string $subPath, QueryParams $params, ?string $body, string $contentType, ?float $timeoutSecs = null): array
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        $response = $this->http->call('POST', $url, $body, $contentType, timeoutSecs: $this->effectiveTimeout($timeoutSecs));
        return self::asArray(Json::decodeData((string) $response->getBody()));
    }

    /** POSTs a raw body and returns the decoded response, for endpoints that answer without a {@code data} envelope. */
    public function postWithBodyNoEnvelope(string $subPath, QueryParams $params, ?string $body, string $contentType, ?float $timeoutSecs = null): mixed
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        $response = $this->http->call('POST', $url, $body, $contentType, timeoutSecs: $this->effectiveTimeout($timeoutSecs));
        return Json::decode((string) $response->getBody());
    }

    /**
     * DELETEs with a JSON body and returns the decoded {@code data}.
     *
     * @return array<string,mixed>
     */
    public function deleteWithBody(string $subPath, QueryParams $params, mixed $body, ?float $timeoutSecs = null): array
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        $response = $this->http->call('DELETE', $url, Json::encode($body), self::CONTENT_TYPE_JSON, timeoutSecs: $this->effectiveTimeout($timeoutSecs));
        return self::asArray(Json::decodeData((string) $response->getBody()));
    }

    /** GETs the raw response, or returns {@code null} if the resource is not found (see {@see deleteResource()}). */
    public function getRaw(string $subPath, QueryParams $params, ?float $timeoutSecs = null): ?ResponseInterface
    {
        try {
            return $this->getRawRequired($subPath, $params, $timeoutSecs);
        } catch (ApifyApiException $e) {
            if (HttpClientCore::isNotFound($e) && $this->swallowsNotFound($subPath)) {
                return null;
            }
            throw $e;
        }
    }

    /** GETs the raw response. Any error is thrown, a 404 included. */
    public function getRawRequired(string $subPath, QueryParams $params, ?float $timeoutSecs = null): ResponseInterface
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        return $this->http->call('GET', $url, timeoutSecs: $this->effectiveTimeout($timeoutSecs));
    }

    /**
     * Whether a 404 on {@code $subPath} resolves to "not found" instead of being rethrown. A 404 on a sub-path
     * always does, on the resource itself only when it is addressed by an ID.
     */
    private function swallowsNotFound(string $subPath): bool
    {
        return $subPath !== '' || $this->hasId;
    }

    /** Sends a HEAD request and returns whether the resource exists. */
    public function headExists(string $subPath, QueryParams $params, ?float $timeoutSecs = null): bool
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        try {
            $this->http->call('HEAD', $url, timeoutSecs: $this->effectiveTimeout($timeoutSecs));
            return true;
        } catch (ApifyApiException $e) {
            if (HttpClientCore::isNotFound($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Opens a streaming GET and returns the body, which is read incrementally. It is not retried, and an error
     * status is thrown as the matching {@see ApifyApiException}.
     */
    public function streamRaw(string $subPath, QueryParams $params): StreamInterface
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
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

    /**
     * PUTs raw bytes.
     *
     * @param array<string,string> $extraHeaders additional headers, for example a caller-set {@code Content-Encoding}
     */
    public function putRaw(
        string $subPath,
        QueryParams $params,
        string $body,
        string $contentType,
        ?float $timeoutSecs = null,
        bool $doNotRetryTimeouts = false,
        array $extraHeaders = []
    ): void {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        $this->http->call('PUT', $url, $body, $contentType, $timeoutSecs ?? $this->requestTimeoutSecs, $doNotRetryTimeouts, $extraHeaders);
    }

    /**
     * The largest {@code waitForFinish} value that is safe for a request timeout: the timeout less a margin,
     * and at most 60 seconds. With no request timeout it is 60.
     */
    private static function serverWaitCapSecs(float $requestTimeoutSecs): int
    {
        if ($requestTimeoutSecs <= TimeoutTiers::INFINITE_SECS) {
            return self::WAIT_REQUEST_SECS;
        }
        $margin = min((float) self::WAIT_TIMEOUT_MARGIN_SECS, $requestTimeoutSecs / 2);
        return min(self::WAIT_REQUEST_SECS, max(0, (int) floor($requestTimeoutSecs - $margin)));
    }

    /** Clamps a {@code waitForFinish} value to what a request can last. {@code null} stays {@code null}. */
    public function clampServerWait(?int $waitForFinishSecs): ?int
    {
        if ($waitForFinishSecs === null) {
            return null;
        }
        return min(max(0, $waitForFinishSecs), self::serverWaitCapSecs($this->http->timeoutTiers()->maxSecs));
    }

    /**
     * Polls a resource with {@code waitForFinish} until {@code $isTerminal} accepts it or {@code $waitSecs}
     * elapse. A {@code null} {@code $waitSecs} waits indefinitely.
     *
     * A 404 counts as replica lag. If it lasts {@see MAX_WAIT_WHEN_JOB_NOT_FOUND_MILLIS}, the 404 is rethrown.
     * A successful poll resets that window.
     *
     * Each poll uses {@code $timeoutSecs} as its request timeout (default {@code 'noTimeout'}) and holds the
     * response for less than that.
     *
     * @param callable(array<string,mixed>):bool $isTerminal
     * @param int|float|string|null $timeoutSecs
     * @return array<string,mixed>
     */
    public function waitForFinish(?int $waitSecs, string $resourceName, callable $isTerminal, int|float|string|null $timeoutSecs = null): array
    {
        $effectiveWaitSecs = $waitSecs !== null
            ? min(max($waitSecs, 0), self::MAX_WAIT_FOR_FINISH_SECS)
            : self::MAX_WAIT_FOR_FINISH_SECS;
        $budgetMillis = $effectiveWaitSecs * 1000;
        $start = self::nowMillis();
        $pollTimeoutSecs = $this->resolveTimeout($timeoutSecs ?? TimeoutTiers::NO_TIMEOUT, TimeoutTiers::TIER_SHORT);
        $serverWaitCap = self::serverWaitCapSecs($pollTimeoutSecs);

        $resource = null;
        $present = false;
        $notFoundSinceMillis = null;
        $notFoundException = null;

        while (true) {
            $elapsed = self::nowMillis() - $start;
            $remainingSecs = intdiv($budgetMillis - $elapsed, 1000);
            $requestSecs = min(min(max($remainingSecs, 0), self::WAIT_REQUEST_SECS), $serverWaitCap);

            $params = new QueryParams();
            $params->addInt('waitForFinish', $requestSecs);

            try {
                $data = $this->getResourceRequired('', $params, $pollTimeoutSecs);
                if (is_array($data)) {
                    $resource = $data;
                    $present = true;
                    $notFoundSinceMillis = null;
                    if ($isTerminal($resource)) {
                        return $resource;
                    }
                }
            } catch (ApifyApiException $e) {
                if (!HttpClientCore::isNotFound($e)) {
                    throw $e;
                }
                $notFoundSinceMillis ??= self::nowMillis();
                $notFoundException = $e;
            }

            if (self::nowMillis() - $start >= $budgetMillis) {
                break;
            }
            if ($notFoundSinceMillis !== null && self::nowMillis() - $notFoundSinceMillis >= self::MAX_WAIT_WHEN_JOB_NOT_FOUND_MILLIS) {
                break;
            }
            usleep((int) (self::WAIT_POLL_INTERVAL_SECS * 1_000_000));
        }

        if ($present && $resource !== null) {
            return $resource;
        }
        if ($notFoundException !== null) {
            throw $notFoundException;
        }
        throw new RuntimeException(
            sprintf('waiting for %s to finish failed: cannot fetch %s details from the server', $resourceName, $resourceName)
        );
    }

    private static function nowMillis(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * The value as an array, or an empty one if it is not.
     *
     * @return array<string,mixed>
     */
    private static function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** Encodes a run input: {@code null} stays {@code null}, a string is sent as it is and anything else as JSON. */
    public static function encodeInputBody(mixed $input): ?string
    {
        if ($input === null) {
            return null;
        }
        return is_string($input) ? $input : Json::encode($input);
    }

    /**
     * Turns {@code username/name} into the {@code username~name} form of an ID. Every {@code /} is replaced,
     * so an ID cannot add path segments to the URL.
     */
    public static function toSafeId(string $id): string
    {
        return str_replace('/', '~', $id);
    }

    /**
     * Percent-encodes a path segment, so an ID or key cannot restructure the path. An empty segment, {@code "."}
     * and {@code ".."} are rejected, since a URL parser would collapse them to the parent resource.
     *
     * @throws InvalidArgumentException if {@code $input} is empty, {@code "."} or {@code ".."}
     */
    public static function encodePathSegment(string $input): string
    {
        if ($input === '' || $input === '.' || $input === '..') {
            throw new InvalidArgumentException(
                sprintf('a URL path segment must be non-empty and must not be "." or "..", got %s', Json::encode($input))
            );
        }
        return rawurlencode($input);
    }

    /** The origin of a URL: {@code scheme://host[:port]}. */
    public static function originOf(string $rawUrl): string
    {
        $rest = $rawUrl;
        $scheme = '';
        $pos = strpos($rest, '://');
        if ($pos !== false) {
            $scheme = substr($rest, 0, $pos + 3);
            $rest = substr($rest, $pos + 3);
        }
        $slash = strpos($rest, '/');
        if ($slash !== false) {
            $rest = substr($rest, 0, $slash);
        }
        return $scheme . $rest;
    }
}
