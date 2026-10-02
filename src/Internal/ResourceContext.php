<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Model\PaginationList;
use Generator;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The resolved context for a resource client: its base URL and the shared HTTP client. The methods
 * here implement the CRUD primitives once, so each resource client stays small and consistent (DRY).
 *
 * @internal
 */
final class ResourceContext
{
    public const CONTENT_TYPE_JSON = 'application/json';
    public const CONTENT_TYPE_JSON_CHARSET = 'application/json; charset=utf-8';

    /** How long to wait between polls while waiting for a run/build to finish, in seconds. */
    private const WAIT_POLL_INTERVAL_SECS = 0.25;

    /** Server-side waitForFinish chunk size (the API caps server waiting at 60 seconds). */
    private const WAIT_REQUEST_SECS = 60;

    /**
     * Safety margin subtracted from the configured per-request timeout when choosing the server-side
     * {@code waitForFinish} value, so the server responds before the client's socket timeout fires.
     */
    private const WAIT_TIMEOUT_MARGIN_SECS = 5;

    /**
     * Finite upper bound used when the caller asks to wait "indefinitely" ({@code waitSecs === null}).
     * The API will not accept "Infinity" and an unbounded loop can spin forever on a transient 404;
     * 999999s (~11.5 days) is effectively indefinite while guaranteeing termination.
     */
    private const MAX_WAIT_FOR_FINISH_SECS = 999999;

    public QueryParams $baseParams;

    private string $apiOrigin;
    private string $publicOrigin;

    /**
     * Optional overall per-request timeout (seconds) applied to every call made through this context.
     * {@code null} means "use the shared client-wide timeout". Set per resource client (e.g. a request
     * queue client created with an explicit {@code timeoutSecs}).
     */
    private ?float $requestTimeoutSecs = null;

    private function __construct(
        public HttpClientCore $http,
        /** Fully-qualified base URL of the resource, e.g. https://api.apify.com/v2/actors/ID. */
        public string $url,
        string $baseUrl,
        /**
         * Whether this context addresses a resource by an explicit ID ({@see single()}) rather than a
         * fixed, ID-less sub-path ({@see collection()}, used e.g. by {@code run.dataset()}). Gates
         * whether {@see getResource()}/{@see getRaw()}/{@see deleteResource()} swallow a 404 on the
         * resource itself ({@code $subPath === ''}): an ID-addressed resource that is missing resolves
         * to {@code null}/no-op, but a 404 on an ID-less chained resource (e.g. a run's default
         * dataset) can mean either the parent or the sub-resource is gone, so it is rethrown instead —
         * matching the reference client's {@code catchNotFoundForResourceOrThrow}.
         */
        private bool $hasId = true,
    ) {
        $this->baseParams = new QueryParams();
        $this->apiOrigin = self::originOf($baseUrl);
        $this->publicOrigin = $this->apiOrigin;
    }

    /** Sets an overall per-request timeout (seconds) for every call made through this context. */
    public function withTimeout(?float $timeoutSecs): self
    {
        $this->requestTimeoutSecs = $timeoutSecs;
        return $this;
    }

    /** The per-context request timeout (seconds), or {@code null} to use the client-wide default. */
    public function requestTimeoutSecs(): ?float
    {
        return $this->requestTimeoutSecs;
    }

    /** Creates a context for a collection endpoint: {@code {base}/{resourcePath}}. */
    public static function collection(HttpClientCore $http, string $baseUrl, string $resourcePath): self
    {
        return new self($http, $baseUrl . '/' . $resourcePath, $baseUrl, hasId: false);
    }

    /** Creates a context for a single resource: {@code {base}/{resourcePath}/{safeId}}. */
    public static function single(HttpClientCore $http, string $baseUrl, string $resourcePath, string $id): self
    {
        $segment = self::encodePathSegment(self::toSafeId($id));
        return new self($http, $baseUrl . '/' . $resourcePath . '/' . $segment, $baseUrl, hasId: true);
    }

    /** Overrides the origin used when building public URLs. */
    public function withPublicOrigin(string $publicBaseUrl): self
    {
        $this->publicOrigin = self::originOf($publicBaseUrl);
        return $this;
    }

    /** This resource's URL with an optional extra path segment appended. */
    public function subUrl(string $subPath = ''): string
    {
        return $subPath === '' ? $this->url : $this->url . '/' . $subPath;
    }

    /** The public (shareable) form of this resource's URL, swapping the API origin for the public one. */
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

    /** Merges the inherited base params with per-call params. */
    public function mergedParams(?QueryParams $params): QueryParams
    {
        return $this->baseParams->copy()->extend($params);
    }

    // ---- CRUD primitives ------------------------------------------------------

    /**
     * GET a single resource, returning its decoded {@code data}, or {@code null} on not-found.
     *
     * @return mixed
     */
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

    /**
     * GET a single resource, returning its decoded {@code data} (propagates errors).
     *
     * @return mixed
     */
    public function getResourceRequired(string $subPath, QueryParams $params, ?float $timeoutSecs = null): mixed
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        $response = $this->http->call('GET', $url, timeoutSecs: $this->effectiveTimeout($timeoutSecs));
        return Json::decodeData((string) $response->getBody());
    }

    /**
     * PUT to update a resource with a JSON-serializable body, returning the decoded {@code data}.
     *
     * @return array<string,mixed>
     */
    public function updateResource(string $subPath, mixed $body, ?float $timeoutSecs = null): array
    {
        $url = $this->mergedParams(new QueryParams())->applyToUrl($this->subUrl($subPath));
        $response = $this->http->call('PUT', $url, Json::encode($body), self::CONTENT_TYPE_JSON, timeoutSecs: $this->effectiveTimeout($timeoutSecs));
        return self::asArray(Json::decodeData((string) $response->getBody()));
    }

    /**
     * Performs a DELETE; a not-found is treated as a successful no-op when this context addresses its
     * resource by ID ({@see $hasId}). A DELETE on an ID-less chained resource (e.g. {@code
     * run.dataset()->delete()}) rethrows instead, since the 404 could mean the parent run is gone.
     */
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

    /**
     * A per-call timeout override (seconds), falling back to this context's own configured timeout
     * ({@see withTimeout()}) when {@code null}, which in turn falls back to the client-wide default.
     */
    private function effectiveTimeout(?float $timeoutSecs): ?float
    {
        return $timeoutSecs ?? $this->requestTimeoutSecs;
    }

    /**
     * Resolves a per-call {@code $timeoutSecs} option (a number of seconds, a timeout tier name, {@see
     * \Apify\Client\Internal\TimeoutTiers::NO_TIMEOUT}, or {@code null} for {@code $defaultTier}'s
     * configured duration) against the client's configured {@see TimeoutTiers}, matching the reference
     * client's per-method tier assignment. See {@see TimeoutTiers} for the tiers and their defaults.
     *
     * @param int|float|string|null $timeoutSecs
     */
    public function resolveTimeout(int|float|string|null $timeoutSecs, string $defaultTier): ?float
    {
        return $this->http->timeoutTiers()->resolve($timeoutSecs, $defaultTier);
    }

    /**
     * GET a paginated listing and build a {@see PaginationList} with each item hydrated.
     *
     * @template T
     * @param callable(array<string,mixed>):T $hydrate
     * @return PaginationList<T>
     */
    public function listResource(string $subPath, QueryParams $params, callable $hydrate): PaginationList
    {
        $data = $this->getResourceRequired($subPath, $params);
        return PaginationList::fromData($data, $hydrate);
    }

    /**
     * Lazily iterates over every item of an offset/limit-paginated listing, fetching pages on demand.
     *
     * Ports the reference client's paginated iterator ({@code _listPaginatedFromCallback}):
     * {@code $limit} caps the TOTAL number of items yielded across all pages ({@code null} = no cap,
     * i.e. all items), while {@code $chunkSize} caps how many items are requested per page
     * ({@code null} = the server default). The two are independent — {@code $limit} is never reused
     * as the page size. {@code $startOffset} is the offset of the first page.
     *
     * @template T
     * @param callable(int,?int):PaginationList<T> $fetchPage receives (offset, pageLimit) and returns that page
     * @return Generator<int,T>
     */
    public static function paginateOffset(int $startOffset, ?int $limit, ?int $chunkSize, callable $fetchPage): Generator
    {
        // First page: request min(limit, chunkSize) items. A null/0 on either side means "unbounded",
        // so the other bound wins (mirrors the reference client's minForLimitParam).
        $page = $fetchPage($startOffset, self::minLimit($limit, $chunkSize));
        $items = $page->getItems();
        foreach ($items as $item) {
            yield $item;
        }

        $total = $page->getTotal();
        // Effective total cap: the smaller of the requested limit (0/null => all) and what exists.
        $cap = min(($limit !== null && $limit > 0) ? $limit : $total, $total);
        $currentOffset = $startOffset + count($items);
        // Items still to yield, bounded both by what remains after the start offset and by the cap.
        $remaining = min($total - $startOffset, $cap) - count($items);

        // Guard on the previous page being non-empty so an over-reported total (a page shorter than
        // its claimed total) terminates instead of looping forever.
        while (count($items) > 0 && $remaining > 0) {
            $page = $fetchPage($currentOffset, self::minLimit($remaining, $chunkSize));
            $items = $page->getItems();
            foreach ($items as $item) {
                yield $item;
            }
            $currentOffset += count($items);
            $remaining -= count($items);
        }
    }

    /**
     * Returns the smaller of two optional positive bounds, treating {@code null} or {@code 0} as
     * "unbounded" (the API treats {@code limit=0} as unset). Mirrors the reference minForLimitParam.
     *
     * Public so {@see \Apify\Client\Resource\DatasetClient::iterateItems()} can reuse it for its own
     * scanned-count-aware pagination loop.
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
     * POST to create a resource with a JSON-serializable body, returning the decoded {@code data}.
     *
     * @return array<string,mixed>
     */
    public function createResource(QueryParams $params, mixed $body): array
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl(''));
        $response = $this->http->call('POST', $url, Json::encode($body), self::CONTENT_TYPE_JSON, timeoutSecs: $this->requestTimeoutSecs);
        return self::asArray(Json::decodeData((string) $response->getBody()));
    }

    /**
     * POST that gets-or-creates a named resource ({@code POST {collection}?name=...}). An optional
     * {@code $schema} is sent in the request body as {@code {"schema": ...}}, matching the reference
     * client's {@code getOrCreate(name, { schema })}.
     *
     * @param array<string,mixed>|null $schema
     * @return array<string,mixed>
     */
    public function getOrCreateNamed(?string $name, ?array $schema = null): array
    {
        $params = new QueryParams();
        if ($name !== null && $name !== '') {
            $params->addString('name', $name);
        }
        $url = $params->applyToUrl($this->subUrl(''));
        $response = $schema !== null
            ? $this->http->call('POST', $url, Json::encode(['schema' => $schema]), self::CONTENT_TYPE_JSON, timeoutSecs: $this->requestTimeoutSecs)
            : $this->http->call('POST', $url, timeoutSecs: $this->requestTimeoutSecs);
        return self::asArray(Json::decodeData((string) $response->getBody()));
    }

    /**
     * POST with an optional raw body and content type, unwrapping the data envelope.
     *
     * @return array<string,mixed>
     */
    public function postWithBody(string $subPath, QueryParams $params, ?string $body, string $contentType): array
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        $response = $this->http->call('POST', $url, $body, $contentType, timeoutSecs: $this->requestTimeoutSecs);
        return self::asArray(Json::decodeData((string) $response->getBody()));
    }

    /**
     * POST with a raw body, parsing the response directly <em>without</em> unwrapping a data
     * envelope. Used by endpoints (e.g. actor input validation) whose response is a plain object.
     *
     * @return mixed
     */
    public function postWithBodyNoEnvelope(string $subPath, QueryParams $params, ?string $body, string $contentType): mixed
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        $response = $this->http->call('POST', $url, $body, $contentType, timeoutSecs: $this->requestTimeoutSecs);
        return Json::decode((string) $response->getBody());
    }

    /**
     * DELETE with a JSON body (used for batch request deletion), unwrapping the data envelope.
     *
     * @return array<string,mixed>
     */
    public function deleteWithBody(string $subPath, QueryParams $params, mixed $body): array
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        $response = $this->http->call('DELETE', $url, Json::encode($body), self::CONTENT_TYPE_JSON, timeoutSecs: $this->requestTimeoutSecs);
        return self::asArray(Json::decodeData((string) $response->getBody()));
    }

    /**
     * GET returning the raw response (no data envelope). Returns {@code null} on not-found, unless
     * this context addresses an ID-less chained resource ({@see $hasId}) and {@code $subPath} is the
     * resource itself ({@code ''}), in which case the 404 is rethrown (see {@see deleteResource()}).
     */
    public function getRaw(string $subPath, QueryParams $params): ?ResponseInterface
    {
        try {
            return $this->getRawRequired($subPath, $params);
        } catch (ApifyApiException $e) {
            if (HttpClientCore::isNotFound($e) && $this->swallowsNotFound($subPath)) {
                return null;
            }
            throw $e;
        }
    }

    /** GET returning the raw response (no data envelope). Propagates every error, including a 404. */
    public function getRawRequired(string $subPath, QueryParams $params): ResponseInterface
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        return $this->http->call('GET', $url, timeoutSecs: $this->requestTimeoutSecs);
    }

    /**
     * Whether a 404 on {@code $subPath} should resolve to "not found" ({@code null}/no-op) rather than
     * being rethrown. True for any fixed sub-path of an ID-addressed resource, and for the resource
     * itself only when it is addressed by ID; an ID-less chained resource's own 404 is ambiguous
     * between "parent missing" and "sub-resource missing" and is always rethrown.
     */
    private function swallowsNotFound(string $subPath): bool
    {
        return $subPath !== '' || $this->hasId;
    }

    /** HEAD request; returns whether the resource exists. */
    public function headExists(string $subPath, QueryParams $params): bool
    {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        try {
            $this->http->call('HEAD', $url, timeoutSecs: $this->requestTimeoutSecs);
            return true;
        } catch (ApifyApiException $e) {
            if (HttpClientCore::isNotFound($e)) {
                return false;
            }
            throw $e;
        }
    }

    /** PUT with raw bytes and a content type, with an explicit per-request timeout and retry control. */
    public function putRaw(
        string $subPath,
        QueryParams $params,
        string $body,
        string $contentType,
        ?float $timeoutSecs = null,
        bool $doNotRetryTimeouts = false
    ): void {
        $url = $this->mergedParams($params)->applyToUrl($this->subUrl($subPath));
        $this->http->call('PUT', $url, $body, $contentType, $timeoutSecs ?? $this->requestTimeoutSecs, $doNotRetryTimeouts);
    }

    // ---- Wait-for-finish ------------------------------------------------------

    /**
     * The largest server-side {@code waitForFinish} value that is safe to send: below the configured
     * per-request timeout by a safety margin (or the API's 60s cap when no finite timeout is set).
     */
    private function serverWaitCapSecs(): int
    {
        $configured = (int) $this->http->requestTimeoutSecs();
        return $configured > 0
            ? max(0, $configured - self::WAIT_TIMEOUT_MARGIN_SECS)
            : self::WAIT_REQUEST_SECS;
    }

    /**
     * Clamps a caller-supplied server-side {@code waitForFinish} value (seconds) to the server wait
     * cap, so a synchronous get/wait never asks the server to hold the connection longer than the
     * client's own per-request timeout. Returns {@code null} for a {@code null} input.
     */
    public function clampServerWait(?int $waitForFinishSecs): ?int
    {
        if ($waitForFinishSecs === null) {
            return null;
        }
        return min(max(0, $waitForFinishSecs), $this->serverWaitCapSecs());
    }

    /**
     * Polls a GET endpoint with {@code waitForFinish} until the resource reaches a terminal state or
     * the wait budget elapses. {@code waitSecs === null} means "wait indefinitely", implemented as a
     * finite but very large bound so the loop always terminates. A transient 404 (replica lag) is
     * treated as "not yet available".
     *
     * @param callable(array<string,mixed>):bool $isTerminal
     * @return array<string,mixed>
     */
    public function waitForFinish(?int $waitSecs, string $resourceName, callable $isTerminal): array
    {
        $effectiveWaitSecs = $waitSecs !== null
            ? min(max($waitSecs, 0), self::MAX_WAIT_FOR_FINISH_SECS)
            : self::MAX_WAIT_FOR_FINISH_SECS;
        $budgetMillis = $effectiveWaitSecs * 1000;
        $start = self::nowMillis();
        $serverWaitCap = $this->serverWaitCapSecs();

        $resource = null;
        $present = false;

        while (true) {
            $elapsed = self::nowMillis() - $start;
            $remainingSecs = intdiv($budgetMillis - $elapsed, 1000);
            $requestSecs = min(min(max($remainingSecs, 0), self::WAIT_REQUEST_SECS), $serverWaitCap);

            $params = new QueryParams();
            $params->addInt('waitForFinish', $requestSecs);

            $data = $this->getResource('', $params);
            if (is_array($data)) {
                $resource = $data;
                $present = true;
                if ($isTerminal($resource)) {
                    return $resource;
                }
            }

            if (self::nowMillis() - $start >= $budgetMillis) {
                break;
            }
            usleep((int) (self::WAIT_POLL_INTERVAL_SECS * 1_000_000));
        }

        if ($present && $resource !== null) {
            return $resource;
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
     * Coerces a decoded value to an associative array. Endpoints that return a resource object
     * always decode to an array; a non-array (e.g. an unexpected {@code null} data field) becomes an
     * empty array so model construction stays type-safe.
     *
     * @return array<string,mixed>
     */
    private static function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Encodes an Actor run input body for {@code ActorClient::start()/validateInput()} and
     * {@code RunClient::metamorph()}: {@code null} stays {@code null}, a {@code string} is sent as
     * raw bytes exactly as given (pair it with a non-default {@code contentType}), and anything else
     * — the normal case, an associative array — is JSON-encoded.
     *
     * Mirrors the reference client's {@code ActorInput}, which accepts a plain object/array of them
     * (serialized to JSON) or raw bytes such as a {@code Buffer} (sent as they are). A PHP string is
     * this client's idiomatic stand-in for the reference client's raw-bytes case, since PHP strings
     * are already byte sequences and the language has no separate binary-buffer type.
     */
    public static function encodeInputBody(mixed $input): ?string
    {
        if ($input === null) {
            return null;
        }
        return is_string($input) ? $input : Json::encode($input);
    }

    // ---- URL / id helpers -----------------------------------------------------

    /**
     * Encodes a resource id so it is safe to embed in a URL path. Apify uses the {@code
     * username~resourcename} form, so every {@code /} in the id is replaced with {@code ~} — not just
     * the first — matching the reference client. Replacing only the first occurrence would leave any
     * further {@code /} in the id as literal path separators once interpolated into a URL, letting an
     * id such as {@code "a/../.."} introduce extra, attacker-controlled path segments.
     */
    public static function toSafeId(string $id): string
    {
        return str_replace('/', '~', $id);
    }

    /**
     * Percent-encodes a single URL path segment, so values interpolated into the path (resource ids,
     * record keys, request ids) cannot break out of the segment or restructure the request path.
     *
     * Rejects an empty segment and the dot-segments {@code "."}/{@code ".."} instead of encoding them:
     * a URL parser (client-side proxy, CDN, or the server itself) resolves dot segments ({@code
     * RFC 3986 §5.2.4}) from the decoded path, so a literal {@code .}/{@code ..} segment can still
     * collapse to the parent resource even though the string itself contains no {@code /}.
     *
     * @throws InvalidArgumentException if {@code $input} is empty or is {@code "."}/{@code ".."}
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

    /** Extracts the origin ({@code scheme://host[:port]}) from a URL, dropping any path. */
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
