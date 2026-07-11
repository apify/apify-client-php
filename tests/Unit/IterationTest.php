<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use Apify\Client\Options\ActorListOptions;
use Apify\Client\Options\DatasetListItemsOptions;
use Apify\Client\Options\ListKeysOptions;
use Apify\Client\Options\StoreListOptions;
use PHPUnit\Framework\TestCase;

/**
 * Hermetic tests for the lazy-iteration helpers, driven entirely by a scripted transport. They
 * exercise the paging/termination logic — single short page, over-reported total, total-cap vs.
 * page-size split, and cursor threading — without touching the network.
 */
final class IterationTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    /**
     * Builds an offset/limit list-page envelope ({@code {"data": {...}}}).
     *
     * @param list<array<string,mixed>> $items
     */
    private static function page(array $items, int $total, int $offset): string
    {
        return Json::encode(['data' => [
            'items' => $items,
            'total' => $total,
            'offset' => $offset,
            'limit' => count($items),
            'count' => count($items),
            'desc' => false,
        ]]);
    }

    /**
     * @param int ...$ids
     * @return list<array<string,mixed>>
     */
    private static function actors(int ...$ids): array
    {
        return array_map(static fn (int $id): array => ['id' => "a$id", 'name' => "actor$id"], $ids);
    }

    public function testSinglePageStopsAfterOneRequest(): void
    {
        // total equals the number of returned items => no second request.
        $transport = (new MockTransport())->queueResponse(200, self::page(self::actors(1, 2, 3), 3, 0));
        $ids = [];
        foreach ($this->client($transport)->actors()->iterate() as $actor) {
            $ids[] = $actor->getId();
        }
        self::assertSame(['a1', 'a2', 'a3'], $ids);
        self::assertSame(1, $transport->callCount());
    }

    public function testOverReportedTotalTerminates(): void
    {
        // The API claims 10 items but only 3 exist; the iterator must stop, not loop forever.
        $transport = (new MockTransport())
            ->queueResponse(200, self::page(self::actors(1, 2, 3), 10, 0))
            ->queueResponse(200, self::page([], 10, 3)); // the follow-up page comes back empty
        $ids = [];
        foreach ($this->client($transport)->actors()->iterate() as $actor) {
            $ids[] = $actor->getId();
        }
        self::assertSame(['a1', 'a2', 'a3'], $ids);
        // One extra fetch is made (remaining > 0) before the empty page stops iteration.
        self::assertSame(2, $transport->callCount());
    }

    public function testLimitIsTotalCapAndChunkSizeIsPageSize(): void
    {
        // limit=5 total across all pages; chunkSize=2 per page => pages of 2, 2, 1.
        $transport = (new MockTransport())
            ->queueResponse(200, self::page(self::actors(1, 2), 100, 0))
            ->queueResponse(200, self::page(self::actors(3, 4), 100, 2))
            ->queueResponse(200, self::page(self::actors(5), 100, 4));
        $ids = [];
        foreach ($this->client($transport)->actors()->iterate(new ActorListOptions(limit: 5), 2) as $actor) {
            $ids[] = $actor->getId();
        }
        self::assertSame(['a1', 'a2', 'a3', 'a4', 'a5'], $ids);
        self::assertSame(3, $transport->callCount());

        // First page requests min(limit=5, chunkSize=2)=2; later pages carry the running offset.
        $uris = array_map(static fn ($r) => (string) $r->getUri(), $transport->received);
        self::assertStringContainsString('offset=0', $uris[0]);
        self::assertStringContainsString('limit=2', $uris[0]);
        self::assertStringContainsString('offset=2', $uris[1]);
        self::assertStringContainsString('offset=4', $uris[2]);
        // The last page is capped by the remaining total (1), not the chunk size (2).
        self::assertStringContainsString('limit=1', $uris[2]);
    }

    public function testLimitCapStopsBeforeExhaustingPages(): void
    {
        // limit=2 with a big first page: only 2 items are yielded and no second request is made.
        $transport = (new MockTransport())
            ->queueResponse(200, self::page(self::actors(1, 2), 100, 0));
        $ids = [];
        foreach ($this->client($transport)->store()->iterate(new StoreListOptions(limit: 2)) as $item) {
            $ids[] = $item->getId();
        }
        self::assertSame(['a1', 'a2'], $ids);
        self::assertSame(1, $transport->callCount());
        self::assertStringContainsString('limit=2', (string) $transport->received[0]->getUri());
    }

    public function testDatasetIterateItemsPagesViaHeaders(): void
    {
        // The dataset-items endpoint returns a bare array and reports pagination via headers.
        $transport = (new MockTransport())
            ->queueResponse(200, Json::encode([['n' => 1], ['n' => 2]]), [
                'X-Apify-Pagination-Total' => '3',
                'X-Apify-Pagination-Offset' => '0',
                'X-Apify-Pagination-Limit' => '2',
            ])
            ->queueResponse(200, Json::encode([['n' => 3]]), [
                'X-Apify-Pagination-Total' => '3',
                'X-Apify-Pagination-Offset' => '2',
                'X-Apify-Pagination-Limit' => '2',
            ]);
        $values = [];
        foreach ($this->client($transport)->dataset('ds')->iterateItems(null, 2) as $item) {
            $values[] = $item['n'];
        }
        self::assertSame([1, 2, 3], $values);
        self::assertSame(2, $transport->callCount());
        self::assertStringContainsString('offset=2', (string) $transport->received[1]->getUri());
    }

    public function testDatasetIterateItemsPreservesFilters(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, Json::encode([['n' => 1]]), [
                'X-Apify-Pagination-Total' => '1',
                'X-Apify-Pagination-Offset' => '0',
                'X-Apify-Pagination-Limit' => '1',
            ]);
        $it = $this->client($transport)->dataset('ds')->iterateItems(new DatasetListItemsOptions(fields: ['n'], clean: true));
        iterator_to_array($it);
        $uri = (string) $transport->received[0]->getUri();
        self::assertStringContainsString('fields=n', $uri);
        self::assertStringContainsString('clean=1', $uri);
    }

    private static function keysPage(bool $isTruncated, ?string $nextKey, string ...$keys): string
    {
        return Json::encode(['data' => [
            'items' => array_map(static fn (string $k): array => ['key' => $k, 'size' => 1], $keys),
            'count' => count($keys),
            'limit' => 1000,
            'isTruncated' => $isTruncated,
            'exclusiveStartKey' => null,
            'nextExclusiveStartKey' => $nextKey,
        ]]);
    }

    public function testIterateKeysThreadsCursor(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::keysPage(true, 'k2', 'k1', 'k2'))
            ->queueResponse(200, self::keysPage(false, null, 'k3'));
        $keys = [];
        foreach ($this->client($transport)->keyValueStore('kvs')->iterateKeys() as $key) {
            $keys[] = $key->getKey();
        }
        self::assertSame(['k1', 'k2', 'k3'], $keys);
        self::assertSame(2, $transport->callCount());
        // The second request must carry the first page's nextExclusiveStartKey.
        self::assertStringContainsString('exclusiveStartKey=k2', (string) $transport->received[1]->getUri());
    }

    public function testIterateKeysStopsWhenNotTruncated(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::keysPage(false, null, 'k1', 'k2'));
        $keys = [];
        foreach ($this->client($transport)->keyValueStore('kvs')->iterateKeys() as $key) {
            $keys[] = $key->getKey();
        }
        self::assertSame(['k1', 'k2'], $keys);
        self::assertSame(1, $transport->callCount());
    }

    public function testIterateKeysRespectsTotalCap(): void
    {
        // limit=2 total: stop after two keys even though the first page is truncated.
        $transport = (new MockTransport())
            ->queueResponse(200, self::keysPage(true, 'k2', 'k1', 'k2'));
        $keys = [];
        foreach ($this->client($transport)->keyValueStore('kvs')->iterateKeys(new ListKeysOptions(limit: 2)) as $key) {
            $keys[] = $key->getKey();
        }
        self::assertSame(['k1', 'k2'], $keys);
        self::assertSame(1, $transport->callCount());
        self::assertStringContainsString('limit=2', (string) $transport->received[0]->getUri());
    }

    public function testIterateKeysLimitZeroIteratesAll(): void
    {
        // limit=0 is a total cap of "unbounded": iterate every page, and never forward limit=0 as a
        // per-page cap (which would short-circuit the iteration after a single page).
        $transport = (new MockTransport())
            ->queueResponse(200, self::keysPage(true, 'k2', 'k1', 'k2'))
            ->queueResponse(200, self::keysPage(false, null, 'k3'));
        $keys = [];
        foreach ($this->client($transport)->keyValueStore('kvs')->iterateKeys(new ListKeysOptions(limit: 0)) as $key) {
            $keys[] = $key->getKey();
        }
        self::assertSame(['k1', 'k2', 'k3'], $keys);
        self::assertSame(2, $transport->callCount());
        self::assertStringNotContainsString('limit=0', (string) $transport->received[0]->getUri());
        self::assertStringNotContainsString('limit=', (string) $transport->received[0]->getUri());
    }
}
