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
 * The HTTP client shared by every resource client. Adds the token, {@code User-Agent}, compression, retries and
 * timeouts to each request.
 *
 * @internal
 */
final class HttpClientCore
{
    /** Status of a rate-limited request. */
    private const RATE_LIMIT_EXCEEDED = 429;

    /** Statuses from this value up are server errors, retried by default. */
    private const MIN_SERVER_ERROR = 500;

    /** Statuses below this value are successes. */
    public const MAX_SUCCESS_STATUS = 300;

    /** Multiplier of the delay between retries. */
    private const BACKOFF_FACTOR = 2;

    /** Multiplier of the timeout on each retry. */
    private const TIMEOUT_BACKOFF_FACTOR = 2;

    private const NOT_FOUND = 404;

    public function __construct(
        private HttpClientInterface $transport,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private ?string $token,
        private string $userAgent,
        private RetryConfig $retry,
        /** Replaces the default compression (brotli, then gzip). */
        private ?HttpCompressorInterface $compressor = null,
    ) {
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    /** The configured timeout tiers. */
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

        // Compressed once, so every retry reuses the compressed body.
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
                // Retried unless the caller opted out of retrying timeouts or transport errors.
                $retryable = $policy->retryTransportErrors && !($doNotRetryTimeouts && $e->isTimeout());
            }

            if (!$retryable || $attempt === $maxAttempts) {
                throw $lastError;
            }

            $this->sleepMillis($this->randomizedDelayMillis($delayMillis));
            $delayMillis = min($delayMillis * self::BACKOFF_FACTOR, $this->retry->maxDelayMillis);
        }

        throw $lastError ?? new TransportException('request failed with no attempts');
    }

    /**
     * Compresses the body when it is large enough and compressible. A body with a caller-set
     * {@code Content-Encoding} is left as it is, so it is never compressed twice.
     *
     * @param array<string,string> $extraHeaders
     * @param HttpCompressorInterface|null $compressor the compressor to use, {@code null} for the default choice
     * @return array{0: string|null, 1: array<string,string>} the body and the headers to send
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
            // Compression::maybeCompress() applies the size gate itself, a configured compressor does not.
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
     * Sets {@code Content-Encoding} and drops a caller-set {@code Content-Length}, which describes the
     * uncompressed body.
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

    /** @param array<string,string> $headers */
    private static function hasHeader(array $headers, string $name): bool
    {
        return self::headerValue($headers, $name) !== null;
    }

    /**
     * The value of a header, or {@code null} if it is not set. Header names are compared case-insensitively.
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

    /** Opens a streaming response. Makes a single attempt. */
    public function stream(string $url): ResponseInterface
    {
        $request = $this->buildRequest('GET', $url, null, '', [], RequestPolicy::AUTHORIZATION_HEADER);
        return $this->transport->sendStreaming($request, $this->retry->timeoutSecs);
    }

    /**
     * Builds a PSR-7 request with the token, User-Agent, content type and extra headers.
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
        if (!$policy->followRedirects && $this->transport instanceof NoRedirectHttpClientInterface) {
            return $this->transport->sendWithoutRedirects($request, $timeoutSecs, $policy->stream);
        }
        return $policy->stream
            ? $this->transport->sendStreaming($request, $timeoutSecs)
            : $this->transport->send($request, $timeoutSecs);
    }

    /** The timeout of an attempt: the base timeout, scaled up on each retry and capped at {@code timeoutMaxSecs}. */
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

    /** A random delay in {@code [delay, 2*delay)}, so retries do not arrive in lockstep. */
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
     * The exception class of each status. Other statuses give {@see ServerException} from 500 up and a plain
     * {@see ApifyApiException} below.
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

    /** Builds the exception for an API error response. */
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

    /** The path and query of a URL, for error messages. */
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

    /** Whether the exception is an API error with status 404, whatever its type. */
    public static function isNotFound(Throwable $e): bool
    {
        return $e instanceof ApifyApiException && $e->getStatusCode() === self::NOT_FOUND;
    }
}
