<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

use JsonException;

/**
 * Thrown when the body of a successful (2xx) response does not parse as valid JSON, for example a
 * non-JSON value served as {@code application/json}. The response arrived in full, so the request is not
 * retried. An error status with an unparsable body throws {@see ApifyApiException} for its status code
 * instead. Mirrors the reference client's {@code InvalidResponseBodyError}.
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
