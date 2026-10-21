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
 * the timeout on the wrapped PSR-18 client instead. Streaming falls back to a normal request (the
 * response body is still readable, just buffered by the underlying client).
 *
 * The body is read in full inside {@see send()}, since a PSR-18 client may return a body it reads lazily:
 * a connection dropped mid-body then surfaces as a {@see TransportException} (which is retried) and not
 * as a failure later, when the response is already taken as complete. The response is returned with a
 * seekable buffered body.
 */
final class Psr18HttpClient implements HttpClientInterface
{
    public function __construct(private ClientInterface $client)
    {
    }

    public function send(RequestInterface $request, float $timeoutSecs): ResponseInterface
    {
        try {
            $response = $this->client->sendRequest($request);
            return $response->withBody(Utils::streamFor($response->getBody()->getContents()));
        } catch (ClientExceptionInterface | \RuntimeException $e) {
            throw new TransportException($e->getMessage(), $e);
        }
    }

    public function sendStreaming(RequestInterface $request, float $timeoutSecs): ResponseInterface
    {
        return $this->send($request, $timeoutSecs);
    }
}
