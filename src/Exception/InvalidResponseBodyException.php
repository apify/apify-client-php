<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

use JsonException;

/**
 * Thrown when a successful (2xx) response body does not parse as valid JSON, typically a document cut
 * short by a connection dropped mid-response. Mirrors the reference client's
 * {@code InvalidResponseBodyError}.
 *
 * For an endpoint whose body this client decodes as JSON, {@see \Apify\Client\Internal\HttpClientCore::call()}
 * runs that decoding step itself before returning, inside its own retry loop, so this is retried exactly
 * like a retryable status code and surfaces only once the retries are exhausted — matching the
 * reference client, which parses a (non-streamed) response body inside its own retry loop for the same
 * reason (a dropped connection is usually fixed by retrying).
 */
final class InvalidResponseBodyException extends ApifyClientException
{
    /** The response body exactly as received, for diagnosing the parse failure. */
    private string $rawBody;

    public function __construct(string $rawBody, JsonException $cause)
    {
        parent::__construct(sprintf("Response body could not be parsed as JSON.\nCause: %s", $cause->getMessage()), 0, $cause);
        $this->rawBody = $rawBody;
    }

    /** The response body exactly as received, for diagnosing the parse failure. */
    public function getRawBody(): string
    {
        return $this->rawBody;
    }
}
