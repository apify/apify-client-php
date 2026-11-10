<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use InvalidArgumentException;

/**
 * Apify storage content URL signing, compatible with the platform's {@code @apify/utilities}.
 *
 * @internal
 */
final class Signatures
{
    /** The version tag in storage content signatures. */
    private const STORAGE_CONTENT_SIGNATURE_VERSION = '0';

    /** The number of leading hex characters of the HMAC digest that are used. */
    private const HMAC_SIGNATURE_HEX_LEN = 30;

    /** The base62 alphabet: digits, lowercase, uppercase. */
    private const BASE62_ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private function __construct()
    {
    }

    /**
     * Computes a signature: the first 30 hex characters of HMAC-SHA256(secret, message) as a big integer in
     * base62 ({@code 0-9a-zA-Z}). Same as upstream {@code createHmacSignature}.
     */
    public static function createHmacSignature(string $secretKey, string $message): string
    {
        $hex = hash_hmac('sha256', $message, $secretKey);
        $truncated = substr($hex, 0, self::HMAC_SIGNATURE_HEX_LEN);
        return self::hexToBase62($truncated);
    }

    /** Rejects a zero or negative expiry, which the signer would read as "never expires" or "already expired". */
    public static function requirePositiveExpiry(?int $expiresInSecs): void
    {
        if ($expiresInSecs !== null && $expiresInSecs <= 0) {
            throw new InvalidArgumentException('expiresInSecs must be positive (omit it for a URL that never expires)');
        }
    }

    /**
     * Builds the signature of a public storage URL, as upstream {@code createStorageContentSignature} does:
     * the base64url encoding, without padding, of {@code "{version}.{expiresAtMillis}.{hmac}"}. The HMAC signs
     * {@code "{version}.{expiresAtMillis}.{resourceId}"}, where {@code expiresAtMillis} is the absolute expiry or
     * {@code 0} for none.
     *
     * @param int|null $expiresInSecs the expiry in seconds ({@code null} for none)
     */
    public static function signStorageContent(string $secretKey, string $resourceId, ?int $expiresInSecs): string
    {
        $expiresAtMillis = $expiresInSecs !== null
            ? (int) round(microtime(true) * 1000) + $expiresInSecs * 1000
            : 0;
        $version = self::STORAGE_CONTENT_SIGNATURE_VERSION;
        $message = $version . '.' . $expiresAtMillis . '.' . $resourceId;
        $hmac = self::createHmacSignature($secretKey, $message);
        $envelope = $version . '.' . $expiresAtMillis . '.' . $hmac;
        return rtrim(strtr(base64_encode($envelope), '+/', '-_'), '=');
    }

    /** Converts a hex string to base62 by long division on bytes, so it needs no bignum extension. */
    private static function hexToBase62(string $hex): string
    {
        $binary = hex2bin($hex);
        if ($binary === false || $binary === '') {
            return '0';
        }
        /** @var list<int> $digits */
        $digits = array_values(unpack('C*', $binary) ?: []);

        $result = '';
        while ($digits !== []) {
            $remainder = 0;
            $quotient = [];
            foreach ($digits as $byte) {
                $accumulator = $remainder * 256 + $byte;
                $q = intdiv($accumulator, 62);
                $remainder = $accumulator % 62;
                if ($quotient !== [] || $q !== 0) {
                    $quotient[] = $q;
                }
            }
            $result = self::BASE62_ALPHABET[$remainder] . $result;
            $digits = $quotient;
        }

        return $result === '' ? '0' : $result;
    }
}
