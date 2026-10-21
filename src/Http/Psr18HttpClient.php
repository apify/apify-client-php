<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use Apify\Client\Exception\TransportException;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Adapts any PSR-18 {@see ClientInterface} to the client's {@see HttpClientInterface}, so an
 * application can plug in its own configured HTTP client (proxy, TLS, connection pool).
 *
 * PSR-18 has no per-request timeout, so the {@code timeoutSecs} argument is ignored here — configure
 * the timeout on the wrapped PSR-18 client instead.
 *
 * {@see send()} reads the response body in full, since a PSR-18 client may return a body it reads lazily:
 * a connection dropped mid-body then surfaces as a {@see TransportException} (which is retried) and not
 * as a failure later, when the response is already taken as complete. The response is returned with a
 * seekable buffered body. {@see sendStreaming()} returns the body as the wrapped client provides it, so
 * it is live only if that client reads it lazily.
 */
final class Psr18HttpClient implements HttpClientInterface
{
    public function __construct(private ClientInterface $client)
    {
    }

    public function send(RequestInterface $request, float $timeoutSecs): ResponseInterface
    {
        $response = $this->sendRequest($request);
        try {
            $body = $response->getBody();
            // Some clients hand back a body they wrote without rewinding it, which would read as empty.
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $contents = $body->getContents();
        } catch (\RuntimeException $e) {
            throw new TransportException($e->getMessage(), $e);
        }
        return $response->withBody(Utils::streamFor($contents));
    }

    public function sendStreaming(RequestInterface $request, float $timeoutSecs): ResponseInterface
    {
        return $this->sendRequest($request);
    }

    private function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($e->getMessage(), $e);
        }
    }
}
