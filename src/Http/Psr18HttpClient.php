<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use Apify\Client\Exception\TransportException;
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
 */
final class Psr18HttpClient implements HttpClientInterface
{
    public function __construct(private ClientInterface $client)
    {
    }

    public function send(RequestInterface $request, float $timeoutSecs): ResponseInterface
    {
        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($e->getMessage(), $e);
        }
    }

    public function sendStreaming(RequestInterface $request, float $timeoutSecs): ResponseInterface
    {
        return $this->send($request, $timeoutSecs);
    }
}
