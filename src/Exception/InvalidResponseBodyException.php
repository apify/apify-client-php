<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

use JsonException;

/**
 * Thrown when a successful (2xx) response body does not parse as valid JSON, typically a document cut
 * short by a connection dropped mid-response. Mirrors the reference client's
 * {@code InvalidResponseBodyError}.
 *
 * Unlike a non-2xx status or a transport failure, this is raised by the decoding step that runs after
 * {@see \Apify\Client\Internal\HttpClientCore::call()} already returned a successful response, so it is
 * not covered by that method's retry loop and always surfaces on the first occurrence.
 */
final class InvalidResponseBodyException extends ApifyClientException
{
    /** The response body exactly as received, for diagnosing the parse failure. */
    private string $rawBody;

    public function __construct(string $rawBody, JsonException $cause)
    {
        parent::__construct(sprintf('Response body could not be parsed as JSON.\nCause: %s', $cause->getMessage()), 0, $cause);
        $this->rawBody = $rawBody;
    }

    /** The response body exactly as received, for diagnosing the parse failure. */
    public function getRawBody(): string
    {
        return $this->rawBody;
    }
}
