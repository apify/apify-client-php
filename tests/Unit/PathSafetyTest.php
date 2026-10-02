<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\ResourceContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Covers URL path-segment sanitization (matches the reference client's `#1011`): an id or key
 * interpolated into a request path is percent-encoded, and an empty or dot-segment value is rejected
 * instead, since a URL parser resolves "." / ".." from the decoded path even when the string contains
 * no literal "/" of its own.
 */
final class PathSafetyTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    // ---- ResourceContext::toSafeId() replaces every "/", not just the first ---------------------

    /** @return array<string,array{0:string,1:string}> */
    public static function toSafeIdProvider(): array
    {
        return [
            'no slash' => ['my-actor', 'my-actor'],
            'single slash (username/actor-name)' => ['jane/my-actor', 'jane~my-actor'],
            'multiple slashes replace every one' => ['a/b/c', 'a~b~c'],
        ];
    }

    /** @dataProvider toSafeIdProvider */
    public function testToSafeIdReplacesAllSlashes(string $id, string $expected): void
    {
        self::assertSame($expected, ResourceContext::toSafeId($id));
    }

    // ---- ResourceContext::encodePathSegment() rejects empty / dot segments -----------------------

    /** @return array<string,array{0:string}> */
    public static function invalidSegmentProvider(): array
    {
        return ['empty string' => [''], 'single dot' => ['.'], 'double dot' => ['..']];
    }

    /** @dataProvider invalidSegmentProvider */
    public function testEncodePathSegmentRejectsInvalidSegment(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        ResourceContext::encodePathSegment($input);
    }

    public function testEncodePathSegmentPercentEncodesSpecialCharacters(): void
    {
        self::assertSame('a%2Fb', ResourceContext::encodePathSegment('a/b'));
        self::assertSame('a%20b', ResourceContext::encodePathSegment('a b'));
    }

    public function testEncodePathSegmentAllowsUnreservedCharactersUnescaped(): void
    {
        self::assertSame('abc-123_.~', ResourceContext::encodePathSegment('abc-123_.~'));
    }

    // ---- End-to-end: empty/dot values in record keys and request ids are rejected before any call ---

    public function testKeyValueStoreGetRecordRejectsEmptyKey(): void
    {
        $transport = new MockTransport();
        $this->expectException(InvalidArgumentException::class);
        $this->client($transport)->keyValueStore('store1')->getRecord('');
        self::assertSame(0, $transport->callCount());
    }

    public function testKeyValueStoreGetRecordRejectsDotDotKey(): void
    {
        $transport = new MockTransport();
        $this->expectException(InvalidArgumentException::class);
        $this->client($transport)->keyValueStore('store1')->getRecord('..');
    }

    public function testRequestQueueGetRequestRejectsEmptyId(): void
    {
        $transport = new MockTransport();
        $this->expectException(InvalidArgumentException::class);
        $this->client($transport)->requestQueue('q1')->getRequest('');
    }

    /** An id resolving to an empty/dot URL-safe form is rejected when the resource client is built. */
    public function testEmptyResourceIdRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client(new MockTransport())->dataset('');
    }

    public function testDotDotResourceIdRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client(new MockTransport())->actor('..');
    }

    /**
     * A record key sent as "a/../../secret" cannot escape the records/ sub-path: the "/" is
     * percent-encoded into "%2F", which decodes back to a single literal segment rather than being
     * resolved by a URL parser into extra path segments.
     */
    public function testRecordKeyWithSlashesCannotEscapeItsSegment(): void
    {
        $transport = (new MockTransport())->queueResponse(200, '', ['Content-Type' => 'text/plain']);
        $this->client($transport)->keyValueStore('store1')->getRecord('a/../../secret');

        $url = (string) $transport->lastRequest()->getUri();
        self::assertStringContainsString('a%2F..%2F..%2Fsecret', $url);
        self::assertStringNotContainsString('a/../../secret', $url);
    }
}
