<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use Apify\Client\Options\DatasetListItemsOptions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Covers {@see \Apify\Client\Resource\RunClient::iterateDatasetItems()} (reference client #1085). */
final class RunIterateDatasetItemsTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    private static function runBody(string $status, string $id = 'run1'): string
    {
        return Json::encode(['data' => ['id' => $id, 'status' => $status]]);
    }

    private static function dataset(int $itemCount): string
    {
        return Json::encode(['data' => ['id' => 'ds1', 'itemCount' => $itemCount]]);
    }

    /** @param list<array<string,int>> $items */
    private static function items(array $items, int $offset): array
    {
        return [200, Json::encode($items), [
            'X-Apify-Pagination-Count' => (string) count($items),
            'X-Apify-Pagination-Offset' => (string) $offset,
            'X-Apify-Pagination-Limit' => '1000',
            'X-Apify-Pagination-Total' => '0',
        ]];
    }

    public function testFinishedRunIsReadToTheEnd(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::runBody('SUCCEEDED'))
            ->queueResponse(200, self::dataset(3))
            ->queueResponse(...self::items([['n' => 0], ['n' => 1], ['n' => 2]], 0))
            ->queueResponse(...self::items([], 3));

        $items = iterator_to_array($this->client($transport)->run('run1')->iterateDatasetItems(), false);

        self::assertSame([['n' => 0], ['n' => 1], ['n' => 2]], $items);
    }

    public function testRunningRunIsFollowedUntilItFinishes(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::runBody('RUNNING'))
            ->queueResponse(200, self::dataset(1))
            ->queueResponse(...self::items([['n' => 0]], 0))
            ->queueResponse(200, self::runBody('SUCCEEDED')) // waitForFinish() once the run is over
            ->queueResponse(200, self::dataset(2))
            ->queueResponse(...self::items([['n' => 1]], 1))
            ->queueResponse(...self::items([], 2));

        $items = iterator_to_array($this->client($transport)->run('run1')->iterateDatasetItems(null, null, 0), false);

        self::assertSame([['n' => 0], ['n' => 1]], $items);
    }

    public function testLimitStopsIterationBeforeTheRunFinishes(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::runBody('RUNNING'))
            ->queueResponse(200, self::dataset(5))
            ->queueResponse(...self::items([['n' => 0], ['n' => 1]], 0));

        $options = new DatasetListItemsOptions(limit: 2);
        $items = iterator_to_array($this->client($transport)->run('run1')->iterateDatasetItems($options), false);

        self::assertSame([['n' => 0], ['n' => 1]], $items);
        self::assertSame(3, $transport->callCount());
        self::assertStringContainsString('limit=2', (string) $transport->lastRequest()->getUri());
    }

    public function testDescIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array($this->client(new MockTransport())->run('run1')->iterateDatasetItems(new DatasetListItemsOptions(desc: true)));
    }
}
