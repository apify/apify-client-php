<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Exception\TransportException;
use Apify\Client\Http\HttpClientInterface;
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

    private const NOT_FOUND = 404;

    public function __construct(
        private HttpClientInterface $transport,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private ?string $token,
        private string $userAgent,
        private RetryConfig $retry,
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
     * Returns {@code min(overall, base * 2^(attempt-1))}: the first attempt uses the base timeout;
     * each retry doubles it (a slow-but-progressing connection gets more time) while never exceeding
     * the overall budget.
     */
    private function attemptTimeout(float $base, int $attempt): float
    {
        $scaled = $base;
        for ($i = 1; $i < $attempt; $i++) {
            $scaled *= 2;
            if ($scaled >= $this->retry->timeoutSecs) {
                return $this->retry->timeoutSecs;
            }
        }
        return min($scaled, $this->retry->timeoutSecs);
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

    /** Builds an {@see ApifyApiException} from an API error response body. */
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

        return new ApifyApiException($status, $type, $message, $attempt, $method, $path, $data);
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

    /** Reports whether an exception represents a "resource not found" API error. */
    public static function isNotFound(Throwable $e): bool
    {
        if (!$e instanceof ApifyApiException || $e->getStatusCode() !== self::NOT_FOUND) {
            return false;
        }
        $type = $e->getType();
        return $type === 'record-not-found'
            || $type === 'record-or-token-not-found'
            || $e->getHttpMethod() === 'HEAD';
    }
}
