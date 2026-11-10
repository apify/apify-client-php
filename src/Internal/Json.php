<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Exception\InvalidResponseBodyException;
use JsonException;

/**
 * JSON encoding and decoding.
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
     * Decodes a JSON response body to PHP values (objects become associative arrays). A malformed body throws
     * {@see InvalidResponseBodyException} and is not retried, since it arrived in full.
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
     * Decodes a JSON response body and returns its {@code data} envelope content, or {@code null} if absent.
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
