<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Exception\ApifyClientException;
use Apify\Client\Exception\ConflictException;
use Apify\Client\Exception\ForbiddenException;
use Apify\Client\Exception\InvalidRequestException;
use Apify\Client\Exception\NotFoundException;
use Apify\Client\Exception\RateLimitException;
use Apify\Client\Exception\ServerException;
use Apify\Client\Exception\TransportException;
use Apify\Client\Exception\UnauthorizedException;
use Apify\Client\Http\HttpClientInterface;
use Apify\Client\Http\HttpCompressorInterface;
use Apify\Client\Http\NoRedirectHttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * The orchestrating HTTP client shared by every resource client. It owns the transport, the optional
 * API token, the {@code User-Agent}, and the retry/timeout policy, and applies them to every request.
 *
 * @internal
 */
final class HttpClientCore
{
    /** Status returned when the per-resource rate limit is hit. */
    private const RATE_LIMIT_EXCEEDED = 429;

    /** Statuses at or above this value are treated as retryable internal server errors. */
    private const MIN_SERVER_ERROR = 500;

    /** Responses with a status below this value are treated as success. */
    public const MAX_SUCCESS_STATUS = 300;

    /** Exponential-backoff multiplier applied to the inter-retry delay after each attempt. */
    private const BACKOFF_FACTOR = 2;

    /** Multiplier applied to the per-attempt timeout on each retry (independent of {@see BACKOFF_FACTOR}). */
    private const TIMEOUT_BACKOFF_FACTOR = 2;

    private const NOT_FOUND = 404;

    public function __construct(
        private HttpClientInterface $transport,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private ?string $token,
        private string $userAgent,
        private RetryConfig $retry,
        /**
         * Overrides the client's default best-effort compression (brotli, falling back to gzip) with a
         * single configured algorithm. {@code null} keeps the default. See
         * {@see \Apify\Client\Http\HttpCompressorInterface}.
         */
        private ?HttpCompressorInterface $compressor = null,
    ) {
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    /** The configured timeout tiers (short/medium/long/max). See {@see TimeoutTiers}. */
    public function timeoutTiers(): TimeoutTiers
    {
        return $this->retry->timeoutTiers;
    }

    /**
     * Sends a request with auth, User-Agent and the retry policy applied.
     *
     * @param array<string,string> $extraHeaders
     * @param RequestPolicy|null   $policy       per-request deviations from the defaults of a call to the API
     *                                           ({@code null} keeps them)
     *
     * @throws ApifyClientException if the policy forbids following redirects and the transport cannot comply
     */
    public function call(
        string $method,
        string $url,
        ?string $body = null,
        string $contentType = '',
        ?float $timeoutSecs = null,
        bool $doNotRetryTimeouts = false,
        array $extraHeaders = [],
        ?RequestPolicy $policy = null
    ): ResponseInterface {
        $policy ??= new RequestPolicy();
        // Refuse before sending anything: a transport that follows redirects would hand the token to another host.
        if (!$policy->followRedirects && !$this->transport instanceof NoRedirectHttpClientInterface) {
            throw new ApifyClientException(sprintf(
                'The HTTP client %s cannot disable redirects, which this request needs to keep the API token from following one. Use a client that implements %s, such as the default GuzzleHttpClient.',
                $this->transport::class,
                NoRedirectHttpClientInterface::class
            ));
        }

        // Compress the body once, up front, so every retry reuses the already-compressed payload.
        if ($policy->compression !== false) {
            [$body, $extraHeaders] = $this->maybeCompressBody($body, $contentType, $extraHeaders, $policy->compression ?? $this->compressor);
        }

        $delayMillis = $this->retry->minDelayMillis;
        $maxAttempts = $this->retry->maxRetries + 1;
        $path = self::extractPath($url);
        $baseTimeout = $timeoutSecs ?? $this->retry->timeoutSecs;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->doAttempt(
                    $method,
                    $url,
                    $body,
                    $contentType,
                    $extraHeaders,
                    $this->attemptTimeout($baseTimeout, $attempt),
                    $policy
                );
                $status = $response->getStatusCode();
                if ($status < self::MAX_SUCCESS_STATUS) {
                    return $response;
                }
                $retryable = self::isStatusRetryable($status, $policy->retryStatusCodes);
                // The caller handles the error response itself once no retry follows, so its body stays unread.
                if (!$policy->throwOnErrorStatus && (!$retryable || $attempt === $maxAttempts)) {
                    return $response;
                }
                $lastError = self::buildApiError($status, (string) $response->getBody(), $attempt, $method, $path);
                $response->getBody()->close();
            } catch (TransportException $e) {
                $lastError = $e;
                // Network/timeout failures are retryable, unless the caller opted out of retrying timeouts or
                // of retrying transport errors at all.
                $retryable = $policy->retryTransportErrors && !($doNotRetryTimeouts && $e->isTimeout());
            }

            if (!$retryable || $attempt === $maxAttempts) {
                throw $lastError;
            }

            $this->sleepMillis($this->randomizedDelayMillis($delayMillis));
            $delayMillis = min($delayMillis * self::BACKOFF_FACTOR, $this->retry->maxDelayMillis);
        }

        // Unreachable in practice (maxAttempts >= 1); defensive.
        throw $lastError ?? new TransportException('request failed with no attempts');
    }

    /**
     * Compresses the request body when it is large enough to be worth it, returning the possibly
     * replaced body together with the (possibly extended) header map. A caller that already set a
     * {@code Content-Encoding} header is left untouched, so an explicitly-encoded body is never
     * double-compressed. A body whose {@code $contentType} already carries its own compression (media,
     * archives, fonts, ...) is left uncompressed too — see {@see Compression::isCompressibleContentType()}.
     *
     * Uses {@code $compressor} when one is given: the one the caller configured on the constructor (the
     * {@code compression} option of {@see \Apify\Client\ApifyClient}) or a per-request one; otherwise falls back to the client's
     * default best-effort choice ({@see Compression::maybeCompress()}: brotli, falling back to gzip).
     *
     * @param array<string,string> $extraHeaders
     * @param HttpCompressorInterface|null $compressor the compressor to use, {@code null} for the default choice
     * @return array{0: string|null, 1: array<string,string>}
     */
    private function maybeCompressBody(?string $body, string $contentType, array $extraHeaders, ?HttpCompressorInterface $compressor): array
    {
        if ($body === null
            || self::hasHeader($extraHeaders, 'Content-Encoding')
            || !Compression::isCompressibleContentType(self::headerValue($extraHeaders, 'Content-Type') ?? $contentType)
        ) {
            return [$body, $extraHeaders];
        }

        if ($compressor !== null) {
            // A configured compressor has no built-in size gate of its own (unlike
            // Compression::maybeCompress() below), so it is applied here.
            if (strlen($body) < Compression::MIN_COMPRESS_BYTES) {
                return [$body, $extraHeaders];
            }
            $compressedBody = $compressor->compress($body);
            if ($compressedBody === null) {
                return [$body, $extraHeaders];
            }
            return [$compressedBody, self::withContentEncoding($extraHeaders, $compressor->contentEncoding())];
        }

        $compressed = Compression::maybeCompress($body);
        if ($compressed === null) {
            return [$body, $extraHeaders];
        }

        [$encoding, $compressedBody] = $compressed;
        return [$compressedBody, self::withContentEncoding($extraHeaders, $encoding)];
    }

    /**
     * Marks a body as compressed and drops any caller-set {@code Content-Length}, which describes the
     * uncompressed body; the transport computes the real one.
     *
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private static function withContentEncoding(array $headers, string $encoding): array
    {
        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, 'Content-Length') === 0) {
                unset($headers[$name]);
            }
        }
        $headers['Content-Encoding'] = $encoding;
        return $headers;
    }

    /**
     * Case-insensitive check for a header key, since HTTP header names are case-insensitive.
     *
     * @param array<string,string> $headers
     */
    private static function hasHeader(array $headers, string $name): bool
    {
        return self::headerValue($headers, $name) !== null;
    }

    /**
     * Case-insensitive lookup of a header value, {@code null} if the header is not set.
     *
     * @param array<string,string> $headers
     */
    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    /** Opens a live streaming response (single attempt, no retry). Used by log streaming. */
    public function stream(string $url): ResponseInterface
    {
        $request = $this->buildRequest('GET', $url, null, '', [], RequestPolicy::AUTHORIZATION_HEADER);
        return $this->transport->sendStreaming($request, $this->retry->timeoutSecs);
    }

    /**
     * Builds a fully-prepared PSR-7 request with auth, User-Agent, content type and extra headers.
     *
     * @param array<string,string> $extraHeaders
     * @param string               $authorizationHeader the header that carries the token
     */
    private function buildRequest(
        string $method,
        string $url,
        ?string $body,
        string $contentType,
        array $extraHeaders,
        string $authorizationHeader
    ): RequestInterface {
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('User-Agent', $this->userAgent);
        if ($this->token !== null && $this->token !== '') {
            $request = $request->withHeader($authorizationHeader, 'Bearer ' . $this->token);
        }
        if ($contentType !== '') {
            $request = $request->withHeader('Content-Type', $contentType);
        }
        foreach ($extraHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }
        return $request;
    }

    /**
     * @param array<string,string> $extraHeaders
     */
    private function doAttempt(
        string $method,
        string $url,
        ?string $body,
        string $contentType,
        array $extraHeaders,
        float $timeoutSecs,
        RequestPolicy $policy
    ): ResponseInterface {
        $request = $this->buildRequest($method, $url, $body, $contentType, $extraHeaders, $policy->authorizationHeader);
        // `call()` has already checked that a transport asked not to follow redirects can do that.
        if (!$policy->followRedirects && $this->transport instanceof NoRedirectHttpClientInterface) {
            return $this->transport->sendWithoutRedirects($request, $timeoutSecs, $policy->stream);
        }
        return $policy->stream
            ? $this->transport->sendStreaming($request, $timeoutSecs)
            : $this->transport->send($request, $timeoutSecs);
    }

    /**
     * Returns {@code min(cap, base * TIMEOUT_BACKOFF_FACTOR^(attempt-1))}: the first attempt uses the
     * base timeout; each retry scales it up by {@see TIMEOUT_BACKOFF_FACTOR} (a slow-but-progressing
     * connection gets more time) while never exceeding {@code $cap} — the configured {@code
     * timeoutMaxSecs} ({@see TimeoutTiers::$maxSecs}), which defaults to the legacy overall {@code
     * timeoutSecs} when not explicitly raised, so a tier/per-call timeout above the legacy default
     * still grows and is honored rather than being silently clipped back down to it.
     */
    private function attemptTimeout(float $base, int $attempt): float
    {
        $cap = $this->retry->timeoutTiers->maxSecs;
        $scaled = $base;
        for ($i = 1; $i < $attempt; $i++) {
            $scaled *= self::TIMEOUT_BACKOFF_FACTOR;
            if ($scaled >= $cap) {
                return $cap;
            }
        }
        return min($scaled, $cap);
    }

    /**
     * Whether a response status is retried: the statuses a request lists itself, or by default rate limits
     * (429) and server errors (5xx).
     *
     * @param list<int>|null $retryStatusCodes
     */
    private static function isStatusRetryable(int $status, ?array $retryStatusCodes): bool
    {
        if ($retryStatusCodes !== null) {
            return in_array($status, $retryStatusCodes, true);
        }
        return $status === self::RATE_LIMIT_EXCEEDED || $status >= self::MIN_SERVER_ERROR;
    }

    /** Returns a delay chosen randomly from {@code [delay, 2*delay)} (exponential backoff + jitter). */
    private function randomizedDelayMillis(float $delayMillis): float
    {
        if ($delayMillis <= 0) {
            return $delayMillis;
        }
        return $delayMillis + (mt_rand() / mt_getrandmax()) * $delayMillis;
    }

    private function sleepMillis(float $millis): void
    {
        $micros = (int) round(max(0.0, $millis) * 1000);
        if ($micros > 0) {
            usleep($micros);
        }
    }

    /**
     * Maps an HTTP status code to the {@see ApifyApiException} subclass that represents it, mirroring
     * the reference client's {@code ApifyApiError.fromResponse()}. Any status with no dedicated
     * subclass below (and no 5xx) stays a plain {@see ApifyApiException}, so {@code instanceof
     * ApifyApiException} keeps matching every error regardless of status.
     *
     * @var array<int,class-string<ApifyApiException>>
     */
    private const EXCEPTION_CLASS_BY_STATUS = [
        400 => InvalidRequestException::class,
        401 => UnauthorizedException::class,
        403 => ForbiddenException::class,
        404 => NotFoundException::class,
        409 => ConflictException::class,
        429 => RateLimitException::class,
    ];

    /** Builds an {@see ApifyApiException} (or the subclass matching the status) from an API error response body. */
    public static function buildApiError(int $status, string $body, int $attempt, string $method, string $path): ApifyApiException
    {
        $type = null;
        $message = null;
        $data = null;

        $decoded = Json::tryDecode($body);
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $error = $decoded['error'];
            $type = isset($error['type']) && is_string($error['type']) ? $error['type'] : null;
            $message = isset($error['message']) && is_string($error['message']) ? $error['message'] : null;
            if (isset($error['data']) && is_array($error['data'])) {
                /** @var array<string,mixed> $data */
                $data = $error['data'];
            }
        }

        if ($message === null) {
            $message = $body === ''
                ? 'unexpected error with status ' . $status
                : 'unexpected error: ' . $body;
        }

        $exceptionClass = self::EXCEPTION_CLASS_BY_STATUS[$status]
            ?? ($status >= self::MIN_SERVER_ERROR ? ServerException::class : ApifyApiException::class);
        return new $exceptionClass($status, $type, $message, $attempt, $method, $path, $data);
    }

    /** Returns the path+query portion of a URL, for error reporting. */
    public static function extractPath(string $url): string
    {
        $rest = $url;
        $scheme = strpos($rest, '://');
        if ($scheme !== false) {
            $rest = substr($rest, $scheme + 3);
        }
        $slash = strpos($rest, '/');
        return $slash !== false ? substr($rest, $slash) : '';
    }

    /**
     * Reports whether an exception represents a "resource not found" API error — any HTTP 404,
     * whatever its machine-readable {@code type}. Matches the reference client's {@code
     * catchNotFoundOrThrow()}, which checks only {@code instanceof NotFoundError}: earlier versions of
     * both clients swallowed only the {@code record-not-found}/{@code record-or-token-not-found} types
     * (plus every HEAD request), which meant a 404 with any other type — for example on an endpoint
     * added after that type list was written — incorrectly propagated as an error instead of resolving
     * to "not found" like every other 404.
     */
    public static function isNotFound(Throwable $e): bool
    {
        return $e instanceof ApifyApiException && $e->getStatusCode() === self::NOT_FOUND;
    }
}
