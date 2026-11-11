<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The transport of the client. An implementation sends one prepared PSR-7 request and returns the PSR-7
 * response. The client adds the token, the {@code User-Agent}, and the retries.
 *
 * An error status is a normal response here. Throw {@see \Apify\Client\Exception\TransportException} only for
 * transport failures: a refused connection, DNS, a timeout, or a connection dropped before the body is complete.
 * The client takes a returned body as complete and does not retry one that fails to parse.
 *
 * Pass an implementation as the {@code httpClient} argument of {@see \Apify\Client\ApifyClient}, for example to
 * share a connection pool, set TLS or proxy options, wrap a PSR-18 client ({@see Psr18HttpClient}), or mock it
 * in tests.
 */
interface HttpClientInterface
{
    /** Sends a single request with a per-attempt timeout (seconds) and buffers the whole response. */
    public function send(RequestInterface $request, float $timeoutSecs): ResponseInterface;

    /**
     * Sends a request and returns a response with a live body stream, for incremental reading. The caller reads it to
     * the end.
     */
    public function sendStreaming(RequestInterface $request, float $timeoutSecs): ResponseInterface;
}
