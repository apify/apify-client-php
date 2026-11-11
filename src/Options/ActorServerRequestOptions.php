<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Http\HttpCompressorInterface;
use Apify\Client\Internal\QueryParams;
use InvalidArgumentException;

/**
 * Configures a request to the web server of a Server Actor
 * ({@see \Apify\Client\Resource\ActorServerClient::request()}). All fields are optional.
 */
final class ActorServerRequestOptions
{
    /** The HTTP method used when none is given. */
    public const DEFAULT_METHOD = 'GET';

    /**
     * @param array<string,mixed>|null  $params      query parameters of the request, converted the same way as for API
     *                                               calls: {@code null} values are left out, booleans are sent as
     *                                               {@code 1}/{@code 0} and a list is joined with commas
     * @param array<string,string>|null $headers     additional headers of the request, {@code Authorization} included;
     *                                               they reach the Actor's server as they are
     * @param string|HttpCompressorInterface|null $compression the compression of the request body: {@code 'brotli'},
     *                                               {@code 'gzip'}, {@code 'zstd'}, or a custom compressor. The body is
     *                                               sent uncompressed when it is not set, since the Actor's server may
     *                                               not decode a compressed body. A body is compressed under the same
     *                                               conditions as for API calls, so set this only if the server
     *                                               decodes the resulting {@code Content-Encoding}.
     */
    public function __construct(
        /** The HTTP method of the request (default {@code GET}). */
        public readonly ?string $method = null,
        public readonly ?array $params = null,
        public readonly ?array $headers = null,
        /**
         * The request body: an array (or any other JSON-serializable value) is sent as JSON, with the content type
         * {@code application/json} unless {@code headers} set another, and a string is sent as it is.
         */
        public readonly mixed $body = null,
        public readonly string|HttpCompressorInterface|null $compression = null,
        /**
         * If {@code true}, the response body is not read until the caller reads it, so a large or endless response
         * can be consumed incrementally ({@see \Apify\Client\Resource\ActorServerResponse::getBodyStream()}). The
         * connection stays open until the caller reads the body to the end or closes it.
         */
        public readonly bool $stream = false,
        /**
         * The {@code X-Actor-Request-ID} header of the request your Server Actor is handling. It is sent on as the
         * same header, replacing one in {@code headers}, so the called Actor charges at that request's pricing tier.
         *
         * @see https://docs.apify.com/platform/actors/development/programming-interface/server-actors
         */
        public readonly ?string $parentRequestId = null,
    ) {
    }

    /** The HTTP method in upper case. */
    public function methodOrDefault(): string
    {
        return $this->method === null || $this->method === '' ? self::DEFAULT_METHOD : strtoupper($this->method);
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        foreach ($this->params ?? [] as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $q->addBool((string) $key, $value);
            } elseif (is_int($value)) {
                $q->addInt((string) $key, $value);
            } elseif (is_float($value)) {
                $q->addFloat((string) $key, $value);
            } elseif (is_string($value)) {
                $q->addString((string) $key, $value);
            } elseif (is_array($value) && array_is_list($value) && self::isScalarList($value)) {
                $q->addRaw((string) $key, implode(',', array_map(static fn (mixed $item): string => is_bool($item) ? ($item ? '1' : '0') : (string) $item, $value)));
            } else {
                throw new InvalidArgumentException(sprintf('ActorServerRequestOptions: the query parameter "%s" must be a scalar or a list of scalars', $key));
            }
        }
    }

    /** @param list<mixed> $values */
    private static function isScalarList(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                return false;
            }
        }
        return true;
    }
}
