<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Exception\ApifyApiException;
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

    /** The configured overall per-request timeout budget, in seconds. */
    public function requestTimeoutSecs(): float
    {
        return $this->retry->timeoutSecs;
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
     */
    public function call(
        string $method,
        string $url,
        ?string $body = null,
        string $contentType = '',
        ?float $timeoutSecs = null,
        bool $doNotRetryTimeouts = false,
        array $extraHeaders = []
    ): ResponseInterface {
        // Compress the body once, up front, so every retry reuses the already-compressed payload.
        [$body, $extraHeaders] = $this->maybeCompressBody($body, $contentType, $extraHeaders);

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
                    $this->attemptTimeout($baseTimeout, $attempt)
                );
                $status = $response->getStatusCode();
                if ($status < self::MAX_SUCCESS_STATUS) {
                    return $response;
                }
                $lastError = self::buildApiError($status, (string) $response->getBody(), $attempt, $method, $path);
                $retryable = self::isStatusRetryable($status);
            } catch (TransportException $e) {
                $lastError = $e;
                // Network/timeout failures are retryable, unless the caller opted out of retrying timeouts.
                $retryable = !($doNotRetryTimeouts && $e->isTimeout());
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
     * Uses the configured {@see $compressor} when one was given to the constructor (the {@code
     * compression} option of {@see \Apify\Client\ApifyClient}); otherwise falls back to the client's
     * default best-effort choice ({@see Compression::maybeCompress()}: brotli, falling back to gzip).
     *
     * @param array<string,string> $extraHeaders
     * @return array{0: string|null, 1: array<string,string>}
     */
    private function maybeCompressBody(?string $body, string $contentType, array $extraHeaders): array
    {
        if ($body === null
            || self::hasHeader($extraHeaders, 'Content-Encoding')
            || !Compression::isCompressibleContentType($contentType)
        ) {
            return [$body, $extraHeaders];
        }

        if ($this->compressor !== null) {
            // A configured compressor has no built-in size gate of its own (unlike
            // Compression::maybeCompress() below), so it is applied here.
            if (strlen($body) < Compression::MIN_COMPRESS_BYTES) {
                return [$body, $extraHeaders];
            }
            $compressedBody = $this->compressor->compress($body);
            if ($compressedBody === null) {
                return [$body, $extraHeaders];
            }
            $extraHeaders['Content-Encoding'] = $this->compressor->contentEncoding();
            return [$compressedBody, $extraHeaders];
        }

        $compressed = Compression::maybeCompress($body);
        if ($compressed === null) {
            return [$body, $extraHeaders];
        }

        [$encoding, $compressedBody] = $compressed;
        $extraHeaders['Content-Encoding'] = $encoding;
        return [$compressedBody, $extraHeaders];
    }

    /**
     * Case-insensitive check for a header key, since HTTP header names are case-insensitive.
     *
     * @param array<string,string> $headers
     */
    private static function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Opens a live streaming response (single attempt, no retry). Used by log streaming. */
    public function stream(string $url): ResponseInterface
    {
        $request = $this->buildRequest('GET', $url, null, '', []);
        return $this->transport->sendStreaming($request, $this->retry->timeoutSecs);
    }

    /**
     * Builds a fully-prepared PSR-7 request with auth, User-Agent, content type and extra headers.
     *
     * @param array<string,string> $extraHeaders
     */
    private function buildRequest(
        string $method,
        string $url,
        ?string $body,
        string $contentType,
        array $extraHeaders
    ): RequestInterface {
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('User-Agent', $this->userAgent);
        if ($this->token !== null && $this->token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->token);
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
        float $timeoutSecs
    ): ResponseInterface {
        $request = $this->buildRequest($method, $url, $body, $contentType, $extraHeaders);
        return $this->transport->send($request, $timeoutSecs);
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

    private static function isStatusRetryable(int $status): bool
    {
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
