<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\Internal\Signatures;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    public function testHmacSignatureIsDeterministicAndBase62(): void
    {
        $sig = Signatures::createHmacSignature('secret-key', 'my-message');
        self::assertSame($sig, Signatures::createHmacSignature('secret-key', 'my-message'));
        self::assertMatchesRegularExpression('/^[0-9a-zA-Z]+$/', $sig);
        self::assertNotSame($sig, Signatures::createHmacSignature('secret-key', 'other-message'));
    }

    /**
     * Known-answer vectors, computed with an arbitrary-precision oracle. They guard the base62 long division
     * (leading zeros, alphabet order) and the base64url envelope.
     */
    public function testKnownAnswerVectors(): void
    {
        self::assertSame('G5BYW8zvRuVZrdxLfboF', Signatures::createHmacSignature('secret-key', 'my-message'));
        self::assertSame('Oj9uljsqvVPaH2iLmW4i', Signatures::createHmacSignature('secret', '0.0.resource-id'));
        self::assertSame('MC4wLk9qOXVsanNxdlZQYUgyaUxtVzRp', Signatures::signStorageContent('secret', 'resource-id', null));
    }

    public function testStorageContentSignatureIsBase64UrlWithoutPadding(): void
    {
        $sig = Signatures::signStorageContent('secret', 'resource-id', null);
        self::assertDoesNotMatchRegularExpression('/[+\/=]/', $sig);

        $decoded = base64_decode(strtr($sig, '-_', '+/'), true);
        self::assertIsString($decoded);
        // Envelope form: "{version}.{expiresAtMillis}.{hmac}"; non-expiring uses expiry 0.
        self::assertStringStartsWith('0.0.', $decoded);
    }

    public function testExpiringSignatureEncodesFutureExpiry(): void
    {
        $sig = Signatures::signStorageContent('secret', 'rid', 3600);
        $decoded = (string) base64_decode(strtr($sig, '-_', '+/'), true);
        $parts = explode('.', $decoded);
        self::assertGreaterThan(0, (int) $parts[1]);
    }
}
