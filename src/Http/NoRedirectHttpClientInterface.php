<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A transport that can return a redirect instead of following it.
 *
 * {@see \Apify\Client\Resource\ActorServerClient::request()} needs it, since its request carries the API token,
 * which must not follow a redirect to a host that the Actor picked. Without it, {@code request()} fails before
 * sending anything. {@see GuzzleHttpClient} implements it. {@see Psr18HttpClient} does not, since a PSR-18
 * client decides on its own whether to follow redirects.
 */
interface NoRedirectHttpClientInterface extends HttpClientInterface
{
    /**
     * Sends a request like {@see HttpClientInterface::send()}, or {@see HttpClientInterface::sendStreaming()} with
     * {@code $stream}, but returns a 3xx response instead of following it.
     */
    public function sendWithoutRedirects(RequestInterface $request, float $timeoutSecs, bool $stream = false): ResponseInterface;
}
