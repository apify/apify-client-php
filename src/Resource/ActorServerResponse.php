<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Exception\InvalidResponseBodyException;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/** The response of an Actor's server to {@see ActorServerClient::request()}. Error statuses are returned too, so check {@see getStatusCode()} or {@see isSuccessful()}. */
final class ActorServerResponse
{
    private const JSON_CONTENT_TYPE_PATTERN = '#^application/(?:[\w.+-]+\+)?json(?:\s*;|\s*$)#i';

    /** @internal */
    public function __construct(private ResponseInterface $response)
    {
    }

    /** The HTTP status code of the response. */
    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    /** Whether the status is a success, that is below 300. */
    public function isSuccessful(): bool
    {
        return $this->getStatusCode() < HttpClientCore::MAX_SUCCESS_STATUS;
    }

    /**
     * The response headers, by header name as the server sent it.
     *
     * @return array<string,list<string>>
     */
    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    /** The values of a header joined with commas, or an empty string if the response has none. The name is case-insensitive. */
    public function getHeaderLine(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    /** The body as raw bytes. With the {@code stream} option, this reads the rest of the stream. */
    public function getBody(): string
    {
        return (string) $this->response->getBody();
    }

    /** The body as a PSR-7 stream. With the {@code stream} option it is live, and its connection stays open until it is read to the end or closed. */
    public function getBodyStream(): StreamInterface
    {
        return $this->response->getBody();
    }

    /**
     * The body parsed by its content type: JSON becomes PHP values, anything else a string. An error response
     * that does not parse comes back as the raw string. With the {@code stream} option, this reads the rest of
     * the stream.
     *
     * @return mixed
     * @throws InvalidResponseBodyException if a success response says it is JSON but is not
     */
    public function getData(): mixed
    {
        $body = $this->getBody();
        if (preg_match(self::JSON_CONTENT_TYPE_PATTERN, $this->getHeaderLine('Content-Type')) !== 1) {
            return $body;
        }
        try {
            return Json::decode($body);
        } catch (InvalidResponseBodyException $e) {
            if (!$this->isSuccessful()) {
                return $body;
            }
            throw $e;
        }
    }

    /** The underlying PSR-7 response. */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
