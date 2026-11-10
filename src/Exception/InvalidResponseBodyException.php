<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

use JsonException;

/**
 * Thrown when the body of a success response is not valid JSON, for example non-JSON served as
 * {@code application/json}. It is not retried, since the response arrived in full. An error status with an
 * unparsable body throws {@see ApifyApiException} instead.
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
