<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Exception\ApifyClientException;
use Apify\Client\Exception\InvalidResponseBodyException;
use Apify\Client\Exception\NotFoundException;
use Apify\Client\Exception\TransportException;
use PHPUnit\Framework\TestCase;

/**
 * Tests of {@see ApifyClientException}: one {@code instanceof} check catches a failed API call and an unparsable
 * response body.
 */
final class ApifyClientExceptionTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    public function testApifyApiExceptionIsAnApifyClientException(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(404, '{"error":{"type":"record-not-found","message":"no"}}');

        try {
            // A chained accessor without an ID always rethrows a 404, giving us an exception to inspect.
            $this->client($transport)->run('r1')->dataset()->delete();
            self::fail('expected ApifyApiException');
        } catch (ApifyApiException $e) {
            self::assertInstanceOf(ApifyClientException::class, $e);
            self::assertInstanceOf(NotFoundException::class, $e);
        }
    }

    public function testInvalidResponseBodyExceptionIsAnApifyClientException(): void
    {
        // maxRetries: 0 so the single queued malformed body surfaces immediately instead of being
        // retried (see HttpClientTest for coverage of the retry behavior itself).
        $transport = (new MockTransport())->queueResponse(200, 'this is not valid JSON {');
        $client = new ApifyClient(token: 't', maxRetries: 0, timeoutSecs: 5, httpClient: $transport);

        try {
            $client->actor('a1')->get();
            self::fail('expected InvalidResponseBodyException');
        } catch (InvalidResponseBodyException $e) {
            self::assertInstanceOf(ApifyClientException::class, $e);
            self::assertSame('this is not valid JSON {', $e->getRawBody());
            self::assertStringContainsString('could not be parsed', $e->getMessage());
        }
    }

    public function testTransportExceptionIsNotAnApifyClientException(): void
    {
        // Network failures and timeouts stay outside the hierarchy.
        // maxRetries: 0, so the queued failure surfaces at once.
        $transport = (new MockTransport())->queueError();
        $client = new ApifyClient(token: 't', maxRetries: 0, timeoutSecs: 5, httpClient: $transport);

        try {
            $client->actor('a1')->get();
            self::fail('expected TransportException');
        } catch (TransportException $e) {
            self::assertNotInstanceOf(ApifyClientException::class, $e);
        }
    }
}
