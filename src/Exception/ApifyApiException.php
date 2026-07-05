<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

use RuntimeException;

/**
 * Thrown for HTTP requests that reach the Apify API but receive a non-success status code.
 *
 * It mirrors the {@code ApifyApiError} of the reference JavaScript client and exposes the parsed
 * error {@see getType() type}, the human-readable {@see getMessage() message}, the HTTP
 * {@see getStatusCode() status code}, the number of the final {@see getAttempt() attempt}, and the
 * request {@see getHttpMethod() method}/{@see getPath() path}.
 */
class ApifyApiException extends RuntimeException
{
    private int $statusCode;
    private ?string $type;
    private int $attempt;
    private string $httpMethod;
    private string $path;

    /** @var array<string,mixed>|null */
    private ?array $data;

    private string $apiMessage;

    /**
     * @param array<string,mixed>|null $data additional structured error data provided by the API
     */
    public function __construct(
        int $statusCode,
        ?string $type,
        string $message,
        int $attempt,
        string $httpMethod,
        string $path,
        ?array $data = null
    ) {
        // Exception::getMessage() is final, so the human-readable prefix is baked into the message
        // passed to the parent constructor (matching the JS reference's formatted error output).
        $errType = ($type === null || $type === '') ? 'unknown' : $type;
        parent::__construct(sprintf('apify API error (status %d, type %s): %s', $statusCode, $errType, $message));
        $this->apiMessage = $message;
        $this->statusCode = $statusCode;
        $this->type = $type;
        $this->attempt = $attempt;
        $this->httpMethod = $httpMethod;
        $this->path = $path;
        $this->data = $data;
    }

    /** The HTTP status code of the error response. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** The machine-readable error type returned by the API (e.g. {@code "record-not-found"}). */
    public function getType(): ?string
    {
        return $this->type;
    }

    /** The number of the API call attempt that produced this error (1-based). */
    public function getAttempt(): int
    {
        return $this->attempt;
    }

    /** The HTTP method of the API call (e.g. {@code "GET"}, {@code "POST"}). */
    public function getHttpMethod(): string
    {
        return $this->httpMethod;
    }

    /** The path of the API endpoint (URL excluding origin). */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Additional structured data provided by the API about the error, if any.
     *
     * @return array<string,mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /** The raw error message returned by the API, without the status/type prefix. */
    public function getApiMessage(): string
    {
        return $this->apiMessage;
    }
}
