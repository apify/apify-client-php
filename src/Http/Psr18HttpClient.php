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
 * Adapts a PSR-18 {@see ClientInterface} to {@see HttpClientInterface}, so the application can plug in its own
 * HTTP client.
 *
 * PSR-18 has no per-request timeout, so {@code timeoutSecs} is ignored. Configure the timeout on the wrapped
 * client.
 *
 * {@see send()} reads the whole body, so a connection dropped mid-body throws {@see TransportException}, which
 * is retried, and the response has a seekable body. {@see sendStreaming()} returns the body as the wrapped
 * client provides it.
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
