<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Exception\InvalidResponseBodyException;
use Apify\Client\Internal\Json;
use Apify\Client\Options\DatasetListItemsOptions;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

final class HttpClientTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(
            token: 'test-token',
            minDelayBetweenRetriesMillis: 1,
            timeoutSecs: 5,
            httpClient: $transport,
        );
    }

    public function testAuthAndUserAgentHeadersAreSent(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'abc']]));
        $this->client($transport)->actor('abc')->get();

        $request = $transport->lastRequest();
        self::assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        self::assertStringStartsWith('ApifyClient/', $request->getHeaderLine('User-Agent'));
    }

    public function testDataEnvelopeIsUnwrapped(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'act1', 'name' => 'my-actor']]));
        $actor = $this->client($transport)->actor('act1')->get();

        self::assertNotNull($actor);
        self::assertSame('act1', $actor->getId());
        self::assertSame('my-actor', $actor->getName());
    }

    public function testNotFoundReturnsNull(): void
    {
        $body = Json::encode(['error' => ['type' => 'record-not-found', 'message' => 'not here']]);
        $transport = (new MockTransport())->queueResponse(404, $body);
        self::assertNull($this->client($transport)->actor('missing')->get());
    }

    public function testServerErrorsAreRetriedThenSucceed(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(500, Json::encode(['error' => ['type' => 'server', 'message' => 'boom']]))
            ->queueResponse(200, Json::encode(['data' => ['id' => 'ok']]));

        $actor = $this->client($transport)->actor('x')->get();
        self::assertSame('ok', $actor?->getId());
        self::assertSame(2, $transport->callCount());
    }

    public function testValidationErrorIsNotRetriedAndThrows(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(400, Json::encode(['error' => ['type' => 'bad-input', 'message' => 'invalid']]));

        try {
            $this->client($transport)->actors()->create(['name' => 'x']);
            self::fail('expected ApifyApiException');
        } catch (ApifyApiException $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('bad-input', $e->getType());
            self::assertStringContainsString('invalid', $e->getApiMessage());
            self::assertSame(1, $transport->callCount());
        }
    }

    public function testMalformedJsonBodyIsRetriedThenSucceeds(): void
    {
        // A successful (2xx) response whose body fails to parse as JSON is retried exactly like a
        // retryable status code — matching the reference client, which parses the body inside its own
        // retry loop for the same reason (a connection dropped mid-response is usually fixed by a retry).
        $transport = (new MockTransport())
            ->queueResponse(200, 'not valid JSON {')
            ->queueResponse(200, Json::encode(['data' => ['id' => 'recovered']]));

        $actor = $this->client($transport)->actor('x')->get();
        self::assertSame('recovered', $actor?->getId());
        self::assertSame(2, $transport->callCount());
    }

    public function testMalformedJsonBodyThrowsOnceRetriesAreExhausted(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, 'not valid JSON {')
            ->queueResponse(200, 'still not valid JSON {');
        $client = new ApifyClient(token: 'test-token', maxRetries: 1, minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);

        try {
            $client->actor('x')->get();
            self::fail('expected InvalidResponseBodyException');
        } catch (InvalidResponseBodyException $e) {
            self::assertSame('still not valid JSON {', $e->getRawBody());
            self::assertSame(2, $transport->callCount());
        }
    }

    public function testNonSeekableResponseBodyIsOnlyReadOnce(): void
    {
        // A PSR-18 transport's response body is not guaranteed seekable/re-readable (the contract
        // HttpClientInterface/Psr18HttpClient exposes); a NoSeekStream reproduces that here. call()
        // must validate/return the body from a single buffered read, since a second
        // `(string) $response->getBody()` on a stream like this one yields '' once already exhausted
        // (GuzzleHttp\Psr7\StreamDecoratorTrait::__toString() skips the rewind-on-seek it does for a
        // seekable stream), which would otherwise make the resource silently look empty.
        $body = new NoSeekStream(Utils::streamFor(Json::encode(['data' => ['id' => 'abc', 'name' => 'my-actor']])));
        $transport = (new MockTransport())->queueRawResponse(new Response(200, [], $body));

        $actor = $this->client($transport)->actor('abc')->get();

        self::assertNotNull($actor);
        self::assertSame('abc', $actor->getId());
        self::assertSame('my-actor', $actor->getName());
    }

    public function testTransportErrorsAreRetried(): void
    {
        $transport = (new MockTransport())
            ->queueError()
            ->queueResponse(200, Json::encode(['data' => ['id' => 'recovered']]));
        $actor = $this->client($transport)->actor('x')->get();
        self::assertSame('recovered', $actor?->getId());
        self::assertSame(2, $transport->callCount());
    }

    public function testBooleanQueryParamsEncodedAsOneZero(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['items' => [], 'total' => 0]]));
        $this->client($transport)->actors()->list(new \Apify\Client\Options\ActorListOptions(my: true, limit: 5));

        $query = $transport->lastRequest()->getUri()->getQuery();
        self::assertStringContainsString('my=1', $query);
        self::assertStringContainsString('limit=5', $query);
    }

    public function testListUnwrapsPaginationEnvelope(): void
    {
        $body = Json::encode(['data' => [
            'total' => 2,
            'offset' => 0,
            'limit' => 10,
            'count' => 2,
            'desc' => false,
            'items' => [['id' => 'a'], ['id' => 'b']],
        ]]);
        $transport = (new MockTransport())->queueResponse(200, $body);
        $page = $this->client($transport)->actors()->list();

        self::assertSame(2, $page->getTotal());
        self::assertCount(2, $page->getItems());
        self::assertSame('a', $page->getItems()[0]->getId());
    }

    public function testDatasetItemsUseHeaderPagination(): void
    {
        $transport = (new MockTransport())->queueResponse(
            200,
            Json::encode([['n' => 1], ['n' => 2], ['n' => 3]]),
            [
                'X-Apify-Pagination-Total' => '42',
                'X-Apify-Pagination-Offset' => '0',
                'X-Apify-Pagination-Limit' => '3',
            ]
        );
        $page = $this->client($transport)->dataset('ds1')->listItems();

        self::assertSame(42, $page->getTotal());
        self::assertSame(3, $page->getCount());
        self::assertSame(1, $page->getItems()[0]['n']);
        self::assertFalse($page->isDesc());
    }

    public function testDatasetItemsDescHeaderTakesPrecedenceOverOption(): void
    {
        // The server-reported X-Apify-Pagination-Desc header must win over the requested option
        // (matches the reference JS client), so a page always reflects what the server actually did.
        $transport = (new MockTransport())->queueResponse(
            200,
            Json::encode([['n' => 1]]),
            ['X-Apify-Pagination-Desc' => 'true']
        );
        $page = $this->client($transport)->dataset('ds1')->listItems(new DatasetListItemsOptions(desc: false));

        self::assertTrue($page->isDesc());
    }

    public function testDatasetItemsDescHeaderFalseTakesPrecedenceOverOption(): void
    {
        // Symmetric case: the header must be genuinely *parsed*, not just checked for presence.
        // A "false" header must win over a contradicting `desc: true` option, so this fails if
        // headerBool() ever regresses to presence-only ("header is set, so trust the option").
        $transport = (new MockTransport())->queueResponse(
            200,
            Json::encode([['n' => 1]]),
            ['X-Apify-Pagination-Desc' => 'false']
        );
        $page = $this->client($transport)->dataset('ds1')->listItems(new DatasetListItemsOptions(desc: true));

        self::assertFalse($page->isDesc());
    }

    public function testDatasetItemsDescFallsBackToOptionWhenHeaderMissing(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode([['n' => 1]]));
        $page = $this->client($transport)->dataset('ds1')->listItems(new DatasetListItemsOptions(desc: true));

        self::assertTrue($page->isDesc());
    }

    public function testValidateInputParsesBareObject(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['valid' => true]));
        self::assertTrue($this->client($transport)->actor('apify/hello-world')->validateInput(['x' => 1]));
    }

    public function testLargeRequestBodyIsCompressed(): void
    {
        if (!function_exists('brotli_compress') && !function_exists('gzencode')) {
            self::markTestSkipped('no compression codec available in this PHP build');
        }
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'a']]));
        // A field well over the 1024-byte threshold forces the request body to be compressed.
        $marker = str_repeat('x', 4096);
        $this->client($transport)->actors()->create(['name' => 'n', 'title' => $marker]);

        $request = $transport->lastRequest();
        $encoding = $request->getHeaderLine('Content-Encoding');
        self::assertContains($encoding, ['br', 'gzip']);

        // The body is actually compressed on the wire, not merely labelled.
        self::assertLessThan(strlen($marker), strlen((string) $request->getBody()));
        // ...yet it round-trips back to the original JSON once decoded.
        self::assertStringContainsString($marker, MockTransport::readBody($request));
    }

    public function testSmallRequestBodyIsNotCompressed(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'a']]));
        $this->client($transport)->actors()->create(['name' => 'n']);

        $request = $transport->lastRequest();
        self::assertSame('', $request->getHeaderLine('Content-Encoding'));
    }

    public function testSafeIdReplacesFirstSlashWithTilde(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'x']]));
        $this->client($transport)->actor('apify/hello-world')->get();
        self::assertStringContainsString('/actors/apify~hello-world', (string) $transport->lastRequest()->getUri());
    }
}
