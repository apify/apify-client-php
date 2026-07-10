<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use Apify\Client\Model\RequestQueueRequest;
use Apify\Client\Options\BatchAddRequestsOptions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Offline behavioral tests for {@see \Apify\Client\Resource\RequestQueueClient::batchAddRequests()}:
 * uniqueKey validation, count/byte chunking, unprocessed-retry from a successful response, and the
 * non-throwing error contract (a failed batch call reports its not-yet-processed requests as
 * unprocessed rather than throwing, preserving earlier chunks' results), matching the JS reference.
 */
final class BatchAddRequestsTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    /** No-delay options so retry tests do not sleep. */
    private function fastOptions(int $maxRetries = 3): BatchAddRequestsOptions
    {
        return new BatchAddRequestsOptions(
            maxUnprocessedRequestsRetries: $maxRetries,
            minDelayBetweenUnprocessedRequestsRetriesMillis: 0,
        );
    }

    /**
     * @param list<string> $uniqueKeys
     * @param list<string> $unprocessedKeys
     */
    private function batchResponse(array $uniqueKeys, array $unprocessedKeys = []): string
    {
        $processed = array_map(
            static fn (string $k) => [
                'uniqueKey' => $k,
                'requestId' => 'id-' . $k,
                'wasAlreadyPresent' => false,
                'wasAlreadyHandled' => false,
            ],
            $uniqueKeys
        );
        $unprocessed = array_map(
            static fn (string $k) => ['uniqueKey' => $k, 'url' => 'https://x/' . $k, 'method' => 'GET'],
            $unprocessedKeys
        );
        return Json::encode(['data' => ['processedRequests' => $processed, 'unprocessedRequests' => $unprocessed]]);
    }

    public function testMissingUniqueKeyThrowsBeforeAnyCall(): void
    {
        $transport = new MockTransport();
        $requests = [new RequestQueueRequest('https://a.com')]; // no uniqueKey

        try {
            $this->client($transport)->requestQueue('q1')->batchAddRequests($requests);
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('uniqueKey', $e->getMessage());
        }
        self::assertSame(0, $transport->callCount());
    }

    public function testApiErrorReportedAsUnprocessedNotThrown(): void
    {
        // Consistent with the JS reference, a failed batch call does not throw: the not-yet-processed
        // requests are returned as unprocessed and the method keeps its non-throwing contract.
        $transport = (new MockTransport())->queueResponse(403, Json::encode(['error' => ['type' => 'insufficient-permissions', 'message' => 'nope']]));
        $requests = [new RequestQueueRequest('https://a.com', 'a')];

        $result = $this->client($transport)->requestQueue('q1')->batchAddRequests($requests, false, $this->fastOptions());

        self::assertSame([], $result->getProcessedRequests());
        self::assertCount(1, $result->getUnprocessedRequests());
        self::assertSame('a', $result->getUnprocessedRequests()[0]->getUniqueKey());
    }

    public function testMultiChunkPreservesEarlierChunksWhenLaterChunkFails(): void
    {
        // 30 requests → two chunks (25 + 5). The first chunk succeeds; the second fails with a 403.
        // The already-processed first chunk must still be returned, not discarded by the failure.
        $keys = array_map(static fn (int $i) => 'u' . $i, range(0, 29));
        $transport = (new MockTransport())
            ->queueResponse(200, $this->batchResponse(array_slice($keys, 0, 25)))
            ->queueResponse(403, Json::encode(['error' => ['type' => 'x', 'message' => 'boom']]));
        $requests = array_map(static fn (string $k) => new RequestQueueRequest('https://x/' . $k, $k), $keys);

        $result = $this->client($transport)->requestQueue('q1')->batchAddRequests($requests, false, $this->fastOptions());

        self::assertCount(25, $result->getProcessedRequests());
        self::assertCount(5, $result->getUnprocessedRequests());
    }

    public function testRetriesOnlyUnprocessedFromSuccessfulResponse(): void
    {
        // First response processes r0 and reports r1 unprocessed; second processes r1.
        $transport = (new MockTransport())
            ->queueResponse(200, $this->batchResponse(['r0'], ['r1']))
            ->queueResponse(200, $this->batchResponse(['r1']));
        $requests = [new RequestQueueRequest('https://a.com', 'r0'), new RequestQueueRequest('https://b.com', 'r1')];

        $result = $this->client($transport)->requestQueue('q1')->batchAddRequests($requests, false, $this->fastOptions());

        self::assertSame(2, $transport->callCount());
        self::assertCount(2, $result->getProcessedRequests());
        self::assertSame([], $result->getUnprocessedRequests());

        // The retry must send only the still-unprocessed request (r1), not the whole batch again.
        $retryBody = Json::decode(MockTransport::readBody($transport->received[1]));
        self::assertIsArray($retryBody);
        self::assertCount(1, $retryBody);
        self::assertSame('r1', $retryBody[0]['uniqueKey']);
    }

    public function testUnprocessedReportedAfterRetriesExhausted(): void
    {
        // Every attempt leaves r0 unprocessed; after maxRetries+1 calls it is reported, without throwing.
        $transport = new MockTransport();
        for ($i = 0; $i < 3; $i++) {
            $transport->queueResponse(200, $this->batchResponse([], ['r0']));
        }
        $requests = [new RequestQueueRequest('https://a.com', 'r0')];

        $result = $this->client($transport)->requestQueue('q1')->batchAddRequests($requests, false, $this->fastOptions(2));

        self::assertSame(3, $transport->callCount()); // 1 + 2 retries
        self::assertSame([], $result->getProcessedRequests());
        self::assertCount(1, $result->getUnprocessedRequests());
        self::assertSame('r0', $result->getUnprocessedRequests()[0]->getUniqueKey());
    }

    public function testChunksByCountLimit(): void
    {
        $keys = array_map(static fn (int $i) => 'u' . $i, range(0, 29)); // 30 > 25
        $transport = (new MockTransport())
            ->queueResponse(200, $this->batchResponse(array_slice($keys, 0, 25)))
            ->queueResponse(200, $this->batchResponse(array_slice($keys, 25)));
        $requests = array_map(static fn (string $k) => new RequestQueueRequest('https://x/' . $k, $k), $keys);

        $result = $this->client($transport)->requestQueue('q1')->batchAddRequests($requests, false, $this->fastOptions());

        self::assertSame(2, $transport->callCount());
        self::assertCount(30, $result->getProcessedRequests());
        // First batch must respect the 25-request count limit.
        $firstBody = Json::decode(MockTransport::readBody($transport->received[0]));
        self::assertIsArray($firstBody);
        self::assertCount(25, $firstBody);
    }

    public function testChunksByPayloadByteSize(): void
    {
        // Three ~4 MiB requests exceed the ~9 MiB payload limit: split into [b0,b1] then [b2].
        $big = str_repeat('x', 4 * 1024 * 1024);
        $keys = ['b0', 'b1', 'b2'];
        $transport = (new MockTransport())
            ->queueResponse(200, $this->batchResponse(['b0', 'b1']))
            ->queueResponse(200, $this->batchResponse(['b2']));
        $requests = array_map(
            static fn (string $k) => (new RequestQueueRequest('https://x/' . $k, $k))->setUserData(['blob' => $big]),
            $keys
        );

        $result = $this->client($transport)->requestQueue('q1')->batchAddRequests($requests, false, $this->fastOptions());

        self::assertSame(2, $transport->callCount());
        self::assertCount(3, $result->getProcessedRequests());
        $firstBody = Json::decode(MockTransport::readBody($transport->received[0]));
        self::assertIsArray($firstBody);
        self::assertCount(2, $firstBody); // byte limit, not the count limit, governed here
    }

    public function testOversizedSingleRequestThrows(): void
    {
        $huge = str_repeat('x', 10 * 1024 * 1024); // > 9 MiB on its own
        $requests = [(new RequestQueueRequest('https://a.com', 'big'))->setUserData(['blob' => $huge])];

        $this->expectException(InvalidArgumentException::class);
        $this->client(new MockTransport())->requestQueue('q1')->batchAddRequests($requests);
    }
}
