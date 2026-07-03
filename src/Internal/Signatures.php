<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

/**
 * Apify storage-content URL signing, byte-for-byte compatible with the platform's
 * {@code @apify/utilities} implementation that the reference clients rely on.
 *
 * @internal
 */
final class Signatures
{
    /** Version tag embedded in storage-content signatures (upstream default). */
    private const STORAGE_CONTENT_SIGNATURE_VERSION = '0';

    /** Number of leading hex characters of the HMAC digest used. */
    private const HMAC_SIGNATURE_HEX_LEN = 30;

    /** Base62 alphabet (digits, then lowercase, then uppercase), matching upstream. */
    private const BASE62_ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private function __construct()
    {
    }

    /**
     * Computes an Apify URL-signing signature, byte-for-byte compatible with upstream
     * {@code createHmacSignature}: HMAC-SHA256(secret, message) as lowercase hex, take the first 30
     * hex characters, interpret them as a big integer, then base62-encode (alphabet {@code 0-9a-zA-Z}).
     */
    public static function createHmacSignature(string $secretKey, string $message): string
    {
        $hex = hash_hmac('sha256', $message, $secretKey);
        $truncated = substr($hex, 0, self::HMAC_SIGNATURE_HEX_LEN);
        return self::hexToBase62($truncated);
    }

    /**
     * Builds a storage-content signature for a resource's public URL, byte-for-byte compatible with
     * upstream {@code createStorageContentSignature}.
     *
     * It signs the message {@code "{version}.{expiresAtMillis}.{resourceId}"} ({@code expiresAtMillis}
     * is the absolute expiry in ms, or {@code 0} for a non-expiring URL) with
     * {@see createHmacSignature}, then returns the base64url (no padding) encoding of
     * {@code "{version}.{expiresAtMillis}.{hmac}"}.
     *
     * @param int|null $expiresInSecs optional expiry in seconds ({@code null} for a non-expiring URL)
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

    /**
     * Interprets a hex string as a big-endian non-negative integer and encodes it in base62.
     * Implemented with byte-wise long division (base 256 → base 62) so it needs no bignum extension.
     */
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
