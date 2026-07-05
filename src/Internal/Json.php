<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use JsonException;

/**
 * Shared JSON (de)serialization for the client.
 *
 * @internal
 */
final class Json
{
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
     * @return mixed the decoded value
     */
    public static function decode(string $body): mixed
    {
        if ($body === '') {
            return null;
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
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
        } catch (JsonException) {
            return null;
        }
    }
}
