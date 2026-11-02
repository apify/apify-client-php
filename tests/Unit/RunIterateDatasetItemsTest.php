<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use Apify\Client\Options\RunIterateDatasetItemsOptions;
use PHPUnit\Framework\TestCase;

/**
 * Offline tests for {@see \Apify\Client\Resource\RunClient::iterateDatasetItems()}, driven by a
 * scripted transport. Each test queues the exact request sequence the algorithm is expected to make.
 */
final class RunIterateDatasetItemsTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, httpClient: $transport);
    }

    private static function runBody(string $status, string $id = 'run1'): string
    {
        return Json::encode(['data' => ['id' => $id, 'status' => $status]]);
    }

    private static function dataset(int $itemCount): string
    {
        return Json::encode(['data' => ['id' => 'ds1', 'itemCount' => $itemCount]]);
    }

    /** @param list<int> $ids */
    private static function items(array $ids): string
    {
        return Json::encode(array_map(static fn (int $id): array => ['id' => $id], $ids));
    }

    /** @return array<string,string> */
    private static function scanned(int $count): array
    {
        return ['X-Apify-Pagination-Count' => (string) $count];
    }

    /** @return list<array<string,string>> the offset/limit query of every request, in order */
    private static function queries(MockTransport $transport): array
    {
        $queries = [];
        foreach ($transport->received as $request) {
            parse_str($request->getUri()->getQuery(), $query);
            $queries[] = $query + ['path' => $request->getUri()->getPath()];
        }
        return $queries;
    }

    /**
     * @param iterable<mixed> $items
     * @return list<int>
     */
    private static function ids(iterable $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $item['id'];
        }
        return $ids;
    }

    public function testFinishedRunIsReadThroughItsLaggingItemCountToTheEnd(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::runBody('SUCCEEDED'))
            ->queueResponse(200, self::dataset(3))
            ->queueResponse(200, self::items([1, 2, 3]), self::scanned(3))
            // `itemCount` (3) lags: a fourth row was pushed. The post-run read finds it...
            ->queueResponse(200, self::items([4]), self::scanned(1))
            // ...and the next page is empty, which ends the iteration.
            ->queueResponse(200, self::items([]), self::scanned(0));

        $ids = self::ids($this->client($transport)->run('run1')->iterateDatasetItems());

        self::assertSame([1, 2, 3, 4], $ids);
        $queries = self::queries($transport);
        self::assertCount(5, $queries);
        self::assertStringEndsWith('/actor-runs/run1/dataset/items', $queries[2]['path']);
        self::assertSame(['0', '3'], [$queries[2]['offset'], $queries[2]['limit']]);
        self::assertSame(['3', '1000'], [$queries[3]['offset'], $queries[3]['limit']]);
        // A non-empty page advances the offset by the page limit (rows requested), not the items returned.
        self::assertSame(['1003', '1000'], [$queries[4]['offset'], $queries[4]['limit']]);
    }

    public function testRunningRunIsPolledUntilItFinishes(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::runBody('RUNNING'))
            ->queueResponse(200, self::dataset(2))
            ->queueResponse(200, self::items([1, 2]), self::scanned(2))
            // waitForFinish() poll: the run is done now.
            ->queueResponse(200, self::runBody('SUCCEEDED'))
            ->queueResponse(200, self::dataset(4))
            ->queueResponse(200, self::items([3, 4]), self::scanned(2))
            ->queueResponse(200, self::items([]), self::scanned(0));

        $ids = self::ids($this->client($transport)->run('run1')->iterateDatasetItems(new RunIterateDatasetItemsOptions(pollIntervalSecs: 0)));

        self::assertSame([1, 2, 3, 4], $ids);
        $queries = self::queries($transport);
        self::assertSame(['2', '2'], [$queries[5]['offset'], $queries[5]['limit']]);
    }

    public function testChunkSizeSplitsPagesAndLimitStopsTheIteration(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::runBody('SUCCEEDED'))
            ->queueResponse(200, self::dataset(10))
            ->queueResponse(200, self::items([11, 12]), self::scanned(2))
            ->queueResponse(200, self::items([13, 14]), self::scanned(2))
            ->queueResponse(200, self::items([15]), self::scanned(1));

        $options = new RunIterateDatasetItemsOptions(offset: 10, limit: 5, chunkSize: 2);
        $ids = self::ids($this->client($transport)->run('run1')->iterateDatasetItems($options));

        // `limit` caps the rows scanned, and the dataset's itemCount (10) is below the offset.
        self::assertSame([11, 12, 13, 14, 15], $ids);
        $queries = self::queries($transport);
        self::assertSame(['10', '2'], [$queries[2]['offset'], $queries[2]['limit']]);
        self::assertSame(['14', '1'], [$queries[4]['offset'], $queries[4]['limit']]);
        self::assertCount(5, $queries);
    }

    public function testFilteredEmptyPageIsConfirmedByAnUnfilteredRead(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::runBody('SUCCEEDED'))
            ->queueResponse(200, self::dataset(0))
            // The first post-run page is empty after `clean`, but a row sits there: it scanned nothing
            // as far as the header says, so the unfiltered one-row read must find it and keep going.
            ->queueResponse(200, self::items([]), self::scanned(0))
            ->queueResponse(200, self::items([7]))
            ->queueResponse(200, self::items([8]), self::scanned(1))
            ->queueResponse(200, self::items([]), self::scanned(0))
            ->queueResponse(200, self::items([]));

        $ids = self::ids($this->client($transport)->run('run1')->iterateDatasetItems(new RunIterateDatasetItemsOptions(clean: true)));

        self::assertSame([8], $ids);
        $queries = self::queries($transport);
        // The confirmation read is unfiltered: one row, no `clean`.
        self::assertSame('1', $queries[3]['limit']);
        self::assertArrayNotHasKey('clean', $queries[3]);
        self::assertSame('1', $queries[2]['clean']);
        self::assertSame('1000', $queries[4]['limit']);
        self::assertCount(7, $queries);
    }

    public function testFilteredIterationEndsWhenTheUnfilteredReadFindsNothing(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::runBody('SUCCEEDED'))
            ->queueResponse(200, self::dataset(0))
            ->queueResponse(200, self::items([]), self::scanned(0))
            ->queueResponse(200, self::items([]));

        $ids = self::ids($this->client($transport)->run('run1')->iterateDatasetItems(new RunIterateDatasetItemsOptions(skipEmpty: true)));

        self::assertSame([], $ids);
        self::assertSame(4, $transport->callCount());
    }

    public function testLastRunIterationIsPinnedToTheRunItResolvedTo(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::runBody('SUCCEEDED', 'resolvedRun'))
            ->queueResponse(200, self::dataset(1))
            ->queueResponse(200, self::items([1]), self::scanned(1))
            ->queueResponse(200, self::items([]), self::scanned(0));

        $ids = self::ids($this->client($transport)->actor('user~actor')->lastRun()->iterateDatasetItems());

        self::assertSame([1], $ids);
        $queries = self::queries($transport);
        self::assertStringEndsWith('/actors/user~actor/runs/last', $queries[0]['path']);
        // Every later request addresses the resolved run by ID, not `runs/last`.
        self::assertStringEndsWith('/actor-runs/resolvedRun/dataset', $queries[1]['path']);
        self::assertStringEndsWith('/actor-runs/resolvedRun/dataset/items', $queries[2]['path']);
    }

    public function testRequestsUseTheLongTierByDefaultAndTheCallerTimeoutWhenGiven(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::runBody('SUCCEEDED'))
            ->queueResponse(200, self::dataset(0))
            ->queueResponse(200, self::items([]), self::scanned(0));
        $client = new ApifyClient(token: 't', timeoutLongSecs: 100, httpClient: $transport);
        iterator_to_array($client->run('run1')->iterateDatasetItems());
        self::assertSame([100.0, 100.0, 100.0], $transport->timeouts);

        $transport = (new MockTransport())
            ->queueResponse(200, self::runBody('SUCCEEDED'))
            ->queueResponse(200, self::dataset(0))
            ->queueResponse(200, self::items([]), self::scanned(0));
        $client = new ApifyClient(token: 't', httpClient: $transport);
        iterator_to_array($client->run('run1')->iterateDatasetItems(null, 7));
        self::assertSame([7.0, 7.0, 7.0], $transport->timeouts);
    }
}
