<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An optional extension of {@see HttpClientInterface} for a transport that can return a redirect
 * response as it is instead of following it.
 *
 * {@see \Apify\Client\Resource\ActorServerClient::request()} needs it: that request carries the API token,
 * which must never follow a redirect to a host the Actor's code picked. A transport that does not implement
 * this interface can still be used for everything else, but {@code request()} fails before it sends anything.
 * {@see GuzzleHttpClient} implements it. {@see Psr18HttpClient} does not, since a PSR-18 client decides on
 * its own whether to follow redirects.
 */
interface NoRedirectHttpClientInterface extends HttpClientInterface
{
    /**
     * Sends a single request like {@see HttpClientInterface::send()}, or like
     * {@see HttpClientInterface::sendStreaming()} when {@code $stream} is {@code true}, but returns a
     * redirect response (3xx) as it is instead of following it.
     */
    public function sendWithoutRedirects(RequestInterface $request, float $timeoutSecs, bool $stream = false): ResponseInterface;
}
