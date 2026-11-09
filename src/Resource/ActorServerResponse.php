<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Exception\InvalidResponseBodyException;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The response of an Actor's web server to {@see ActorServerClient::request()}. Any status the server answers
 * with is returned, an error status included: check {@see getStatusCode()} or {@see isSuccessful()}.
 */
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

    /**
     * The response body as raw bytes. With the {@code stream} request option, this reads the rest of the stream.
     */
    public function getBody(): string
    {
        return (string) $this->response->getBody();
    }

    /**
     * The response body as a PSR-7 stream. It is live, and holds its connection open until it is read to the end
     * or closed, when the request was made with the {@code stream} option.
     */
    public function getBodyStream(): StreamInterface
    {
        return $this->response->getBody();
    }

    /**
     * The response body parsed by its content type: JSON is decoded to PHP values (objects become associative
     * arrays), and any other body is returned as a string. An error response whose body does not parse as its
     * content type comes back as the raw string. With the {@code stream} request option, this reads the rest of the
     * stream.
     *
     * @return mixed
     * @throws InvalidResponseBodyException if the body of a success response is not valid JSON, although its
     *                                      content type says it is
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
