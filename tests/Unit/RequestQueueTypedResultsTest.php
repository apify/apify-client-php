<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use Apify\Client\Model\RequestQueueRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Offline behavioral tests for the {@see \Apify\Client\Resource\RequestQueueClient} operations that
 * return typed models instead of a raw decoded array: {@code listHead()}, {@code listAndLockHead()},
 * {@code listRequests()}, {@code prolongRequestLock()}, {@code unlockRequests()}, and
 * {@code batchDeleteRequests()}.
 */
final class RequestQueueTypedResultsTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', httpClient: $transport);
    }

    public function testListHeadExposesQueueModifiedAt(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => [
            'limit' => 10,
            'queueModifiedAt' => '2026-08-01T00:00:00.000Z',
            'hadMultipleClients' => false,
            'items' => [],
        ]]));

        $head = $this->client($transport)->requestQueue('q1')->listHead(10);

        self::assertSame('2026-08-01T00:00:00.000Z', $head->getQueueModifiedAt());
        self::assertFalse($head->hadMultipleClients());
    }

    public function testListAndLockHeadReturnsTypedResult(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => [
            'limit' => 5,
            'queueModifiedAt' => '2026-08-01T00:00:00.000Z',
            'hadMultipleClients' => true,
            'lockSecs' => 60,
            'queueHasLockedRequests' => true,
            'clientKey' => 'my-client-key',
            'items' => [
                ['id' => 'r1', 'uniqueKey' => 'r1', 'url' => 'https://a.com', 'retryCount' => 2, 'lockExpiresAt' => '2026-08-01T00:01:00.000Z'],
            ],
        ]]));

        $locked = $this->client($transport)->requestQueue('q1')->listAndLockHead(60, 5);

        self::assertCount(1, $locked->getItems());
        self::assertSame('r1', $locked->getItems()[0]->getId());
        self::assertSame(2, $locked->getItems()[0]->getRetryCount());
        self::assertSame('2026-08-01T00:01:00.000Z', $locked->getItems()[0]->getLockExpiresAt());
        self::assertSame(60, $locked->getLockSecs());
        self::assertTrue($locked->queueHasLockedRequests());
        self::assertSame('my-client-key', $locked->getClientKey());
        self::assertTrue($locked->hadMultipleClients());
        self::assertSame('2026-08-01T00:00:00.000Z', $locked->getQueueModifiedAt());
    }

    public function testListRequestsReturnsTypedPage(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => [
            'limit' => 2,
            'cursor' => 'c0',
            'nextCursor' => 'c1',
            'items' => [
                ['id' => 'r1', 'uniqueKey' => 'r1', 'url' => 'https://a.com'],
                ['id' => 'r2', 'uniqueKey' => 'r2', 'url' => 'https://b.com'],
            ],
        ]]));

        $page = $this->client($transport)->requestQueue('q1')->listRequests();

        self::assertCount(2, $page->getItems());
        self::assertSame('r1', $page->getItems()[0]->getId());
        self::assertSame('c1', $page->getNextCursor());
        self::assertSame('c0', $page->getCursor());
    }

    public function testProlongRequestLockReturnsTypedResult(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => [
            'lockExpiresAt' => '2026-08-01T00:01:00.000Z',
        ]]));

        $lockInfo = $this->client($transport)->requestQueue('q1')->prolongRequestLock('r1', 30);

        self::assertSame('2026-08-01T00:01:00.000Z', $lockInfo->getLockExpiresAt());
    }

    public function testUnlockRequestsReturnsTypedResult(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => [
            'unlockedCount' => 3,
        ]]));

        $result = $this->client($transport)->requestQueue('q1')->unlockRequests();

        self::assertSame(3, $result->getUnlockedCount());
    }

    public function testBatchDeleteRequestsSendsIdentifiersAndReturnsTypedResult(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => [
            'processedRequests' => [['id' => 'r1', 'uniqueKey' => 'r1']],
            'unprocessedRequests' => [['id' => 'r2', 'uniqueKey' => 'r2']],
        ]]));

        $toDelete = [
            (new RequestQueueRequest())->setId('r1'),
            (new RequestQueueRequest())->setId('r2'),
        ];
        $result = $this->client($transport)->requestQueue('q1')->batchDeleteRequests($toDelete);

        self::assertCount(1, $result->getProcessedRequests());
        self::assertSame('r1', $result->getProcessedRequests()[0]->getId());
        self::assertCount(1, $result->getUnprocessedRequests());
        self::assertSame('r2', $result->getUnprocessedRequests()[0]->getId());

        $sentBody = Json::decode(MockTransport::readBody($transport->lastRequest()));
        self::assertSame([['id' => 'r1'], ['id' => 'r2']], $sentBody);
    }

    public function testBatchDeleteRequestsRejectsEmptyInputBeforeAnyCall(): void
    {
        $transport = new MockTransport();

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->client($transport)->requestQueue('q1')->batchDeleteRequests([]);
        } finally {
            self::assertSame(0, $transport->callCount());
        }
    }

    public function testBatchDeleteRequestsRejectsOversizedInputBeforeAnyCall(): void
    {
        $transport = new MockTransport();
        $requests = array_map(
            static fn (int $i) => (new RequestQueueRequest())->setId('r' . $i),
            range(0, 25) // 26 > the 25-per-call limit
        );

        try {
            $this->client($transport)->requestQueue('q1')->batchDeleteRequests($requests);
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('26', $e->getMessage());
        }
        self::assertSame(0, $transport->callCount());
    }
}
