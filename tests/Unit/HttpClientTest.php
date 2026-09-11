<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Internal\Json;
use Apify\Client\Options\DatasetListItemsOptions;
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
