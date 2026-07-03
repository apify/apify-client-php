<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use Apify\Client\Exception\TransportException;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The default {@see HttpClientInterface}, backed by Guzzle.
 *
 * The per-attempt timeout is applied to each request by the orchestrating client. Non-2xx statuses
 * are returned as normal responses ({@code http_errors} disabled); only connection/timeout failures
 * are thrown as {@see TransportException}.
 */
final class GuzzleHttpClient implements HttpClientInterface
{
    /** Connection-establishment timeout (distinct from the per-request timeout the client applies). */
    private const CONNECT_TIMEOUT_SECS = 30.0;

    private Guzzle $client;

    public function __construct(?Guzzle $client = null)
    {
        $this->client = $client ?? new Guzzle();
    }

    public function send(RequestInterface $request, float $timeoutSecs): ResponseInterface
    {
        return $this->doSend($request, $timeoutSecs, false);
    }

    public function sendStreaming(RequestInterface $request, float $timeoutSecs): ResponseInterface
    {
        return $this->doSend($request, $timeoutSecs, true);
    }

    private function doSend(RequestInterface $request, float $timeoutSecs, bool $stream): ResponseInterface
    {
        try {
            return $this->client->send($request, [
                'http_errors' => false,
                'allow_redirects' => true,
                'connect_timeout' => self::CONNECT_TIMEOUT_SECS,
                'timeout' => $timeoutSecs,
                'stream' => $stream,
            ]);
        } catch (ConnectException $e) {
            // Guzzle surfaces cURL connect/read timeouts (errno 28) as ConnectException.
            throw new TransportException($e->getMessage(), $e, $this->isTimeout($e));
        } catch (GuzzleException $e) {
            throw new TransportException($e->getMessage(), $e, false);
        }
    }

    private function isTimeout(ConnectException $e): bool
    {
        $context = $e->getHandlerContext();
        $errno = isset($context['errno']) ? (int) $context['errno'] : 0;
        // 28 == CURLE_OPERATION_TIMEDOUT. Fall back to a substring check for non-cURL handlers.
        return $errno === 28 || stripos($e->getMessage(), 'timed out') !== false;
    }
}
