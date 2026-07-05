<?php

declare(strict_types=1);

namespace Apify\Client\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The replaceable transport contract of the client.
 *
 * Implementations are responsible only for sending a single, fully-prepared PSR-7 request and
 * returning the raw PSR-7 response. Authentication, the {@code User-Agent} header, retries and
 * (de)serialization are handled by the client, so a backend only needs to perform one network
 * round-trip.
 *
 * A non-2xx HTTP status is <b>not</b> an error at this layer — return it as a normal response.
 * Only transport-level failures (connection refused, DNS, timeout) should be thrown, as an
 * {@see \Apify\Client\Exception\TransportException}.
 *
 * Swap the default implementation ({@see GuzzleHttpClient}) via the {@code httpClient} constructor
 * argument of {@see \Apify\Client\ApifyClient} to share a connection pool, customize TLS/proxy
 * settings, wrap any PSR-18 client ({@see Psr18HttpClient}), or inject a mock in tests.
 */
interface HttpClientInterface
{
    /** Sends a single request with a per-attempt timeout (seconds) and buffers the whole response. */
    public function send(RequestInterface $request, float $timeoutSecs): ResponseInterface;

    /**
     * Sends a single request and returns a response whose body is a live stream, for incremental
     * consumption (used by log streaming). The caller reads the body stream to completion.
     */
    public function sendStreaming(RequestInterface $request, float $timeoutSecs): ResponseInterface;
}
