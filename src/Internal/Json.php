<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Exception\InvalidResponseBodyException;
use JsonException;

/**
 * Shared JSON (de)serialization for the client.
 *
 * @internal
 */
final class Json
{
    /** Maximum nesting depth passed to {@see json_decode()} (PHP's own default). */
    private const MAX_JSON_DEPTH = 512;

    private function __construct()
    {
    }

    /** Serializes a value to a JSON string. */
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decodes a JSON string into PHP values (objects become associative arrays).
     *
     * Every caller of this method (directly or via {@see decodeData()}) decodes a response body the
     * client just received over the wire, never a value the caller constructed itself (that path is
     * {@see encode()}), so a malformed body is wrapped as an {@see InvalidResponseBodyException} instead
     * of leaking the raw {@see JsonException}. See {@see \Apify\Client\Internal\HttpClientCore::call()}'s
     * {@code $validateBody} param for why this is retried rather than thrown on the first occurrence.
     *
     * @return mixed the decoded value
     */
    public static function decode(string $body): mixed
    {
        if ($body === '') {
            return null;
        }
        try {
            return json_decode($body, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidResponseBodyException($body, $e);
        }
    }

    /**
     * Decodes a JSON response body wrapped in a {@code {"data": ...}} envelope, returning the
     * unwrapped {@code data} value (or {@code null} if it is absent/null).
     *
     * @return mixed the unwrapped data
     */
    public static function decodeData(string $body): mixed
    {
        $decoded = self::decode($body);
        if (is_array($decoded) && array_key_exists('data', $decoded)) {
            return $decoded['data'];
        }
        return null;
    }

    /** Attempts to decode a body, returning {@code null} on any parse error. */
    public static function tryDecode(string $body): mixed
    {
        try {
            return self::decode($body);
        } catch (InvalidResponseBodyException) {
            return null;
        }
    }
}
