<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Http\HttpCompressorInterface;

/**
 * Per-request deviations from the default behaviour of {@see HttpClientCore::call()}. The defaults describe
 * a call to the Apify API itself; {@see \Apify\Client\Resource\ActorServerClient} overrides them to talk to an
 * Actor's own web server, whose responses and side effects the client must not second-guess.
 *
 * @internal
 */
final class RequestPolicy
{
    /** The name of the header the API reads the token from. */
    public const AUTHORIZATION_HEADER = 'Authorization';

    /** The header that carries the token to an Actor server. The platform removes it before the Actor sees the request. */
    public const SERVER_AUTHORIZATION_HEADER = 'X-Apify-Authorization';

    /**
     * @param HttpCompressorInterface|false|null $compression  the compressor for this request's body, {@code false}
     *                                                          to send it uncompressed, {@code null} for the
     *                                                          client's default
     * @param string                             $authorizationHeader the header the token is sent in
     * @param bool                               $throwOnErrorStatus  whether an error status throws; with {@code false}
     *                                                          the error response is returned once no retry follows
     * @param list<int>|null                     $retryStatusCodes    the statuses to retry, {@code null} for 429 and 5xx
     * @param bool                               $retryTransportErrors whether a transport error, a timeout included,
     *                                                          is retried
     * @param bool                               $followRedirects     whether the transport follows redirects
     * @param bool                               $stream              whether the response body is left unread
     */
    public function __construct(
        public readonly HttpCompressorInterface|false|null $compression = null,
        public readonly string $authorizationHeader = self::AUTHORIZATION_HEADER,
        public readonly bool $throwOnErrorStatus = true,
        public readonly ?array $retryStatusCodes = null,
        public readonly bool $retryTransportErrors = true,
        public readonly bool $followRedirects = true,
        public readonly bool $stream = false,
    ) {
    }
}
