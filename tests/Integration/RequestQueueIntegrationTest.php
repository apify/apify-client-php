<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Model\RequestQueueRequest;
use Apify\Client\Options\ListRequestsOptions;
use Apify\Client\Options\PaginateRequestsOptions;
use Apify\Client\Options\StorageListOptions;

final class RequestQueueIntegrationTest extends IntegrationTestCase
{
    public function testListRequestQueues(): void
    {
        $client = $this->requireClient();
        $page = $client->requestQueues()->list(new StorageListOptions(limit: 5));
        self::assertLessThanOrEqual(5, count($page->getItems()));
        self::assertSame(count($page->getItems()), $page->getCount());
        self::assertGreaterThanOrEqual(count($page->getItems()), $page->getTotal());
    }

    public function testGetRequestQueue(): void
    {
        $client = $this->requireClient();
        $rq = $client->requestQueues()->getOrCreate(self::uniqueName('rq-get'));
        try {
            $got = $client->requestQueue((string) $rq->getId())->get();
            self::assertNotNull($got);
            self::assertSame($rq->getId(), $got->getId());
        } finally {
            $client->requestQueue((string) $rq->getId())->delete();
        }
    }

    public function testRequestQueueCrudFlow(): void
    {
        $client = $this->requireClient();
        $rq = $client->requestQueues()->getOrCreate(self::uniqueName('rq-crud'));
        try {
            $queue = $client->requestQueue((string) $rq->getId());
            self::assertNotNull($queue->get());

            $info = $queue->addRequest((new RequestQueueRequest('https://example.com', 'example'))->setMethod('GET'));
            self::assertNotNull($info->getRequestId());
            self::assertNotSame('', $info->getRequestId());

            $got = $queue->getRequest((string) $info->getRequestId());
            self::assertNotNull($got);
            self::assertSame('https://example.com', $got->getUrl());

            self::assertNotEmpty($queue->listHead(10)->getItems());
            $queue->update(['name' => self::uniqueName('rq-renamed')]);
            $queue->deleteRequest((string) $info->getRequestId());
        } finally {
            $client->requestQueue((string) $rq->getId())->delete();
        }
    }

    public function testRequestQueuePaginateMultiplePages(): void
    {
        $client = $this->requireClient();
        $rq = $client->requestQueues()->getOrCreate(self::uniqueName('rq-page'));
        try {
            $queue = $client->requestQueue((string) $rq->getId());
            $total = 5;
            for ($i = 0; $i < $total; $i++) {
                $url = 'https://example.com/' . $i;
                $queue->addRequest(new RequestQueueRequest($url, $url));
            }
            $seen = [];
            foreach ($queue->paginateRequests(new PaginateRequestsOptions(maxPageLimit: 2)) as $request) {
                $seen[(string) $request->getUrl()] = true;
            }
            self::assertCount($total, $seen);
        } finally {
            $client->requestQueue((string) $rq->getId())->delete();
        }
    }

    public function testRequestQueueBatchAddRequests(): void
    {
        $client = $this->requireClient();
        $rq = $client->requestQueues()->getOrCreate(self::uniqueName('rq-batch'));
        try {
            $queue = $client->requestQueue((string) $rq->getId());
            $total = 30; // > 25, so the client must split into multiple chunks
            $requests = [];
            for ($i = 0; $i < $total; $i++) {
                $url = 'https://batch.example.com/' . $i;
                $requests[] = new RequestQueueRequest($url, $url);
            }
            $result = $queue->batchAddRequests($requests);
            self::assertCount($total, $result->getProcessedRequests());
            self::assertSame([], $result->getUnprocessedRequests());
        } finally {
            $client->requestQueue((string) $rq->getId())->delete();
        }
    }

    public function testRequestQueueLockLifecycle(): void
    {
        $client = $this->requireClient();
        $rq = $client->requestQueues()->getOrCreate(self::uniqueName('rq-lock'));
        try {
            $queue = $client->requestQueue((string) $rq->getId())->withClientKey('php-test-client-key');
            $info = $queue->addRequest(new RequestQueueRequest('https://lock.example.com', 'lock'));
            self::assertArrayHasKey('items', $queue->listRequests(new ListRequestsOptions()));
            $queue->listRequests(new ListRequestsOptions(
                filter: [ListRequestsOptions::FILTER_LOCKED, ListRequestsOptions::FILTER_PENDING]
            ));
            self::assertArrayHasKey('items', $queue->listAndLockHead(60, 10));
            $queue->prolongRequestLock((string) $info->getRequestId(), 30);
            $queue->deleteRequestLock((string) $info->getRequestId());
            self::assertIsArray($queue->unlockRequests());
        } finally {
            $client->requestQueue((string) $rq->getId())->delete();
        }
    }
}
