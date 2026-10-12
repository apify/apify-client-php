<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use Apify\Client\Options\ActorListOptions;
use Apify\Client\Options\DatasetListItemsOptions;
use Apify\Client\Options\ListKeysOptions;
use Apify\Client\Options\PaginateRequestsOptions;
use Apify\Client\Options\StoreListOptions;
use PHPUnit\Framework\TestCase;

/**
 * Hermetic tests for the lazy-iteration helpers, driven entirely by a scripted transport. They
 * exercise the paging/termination logic — over-reported total, total-cap vs. page-size split, cursor
 * threading, and (matching the reference client) that the stop condition never consults a listing's
 * reported {@code total}: with no explicit {@code limit}, the loop always ends only once a page scans
 * no rows, so a resource that keeps growing during the iteration is not cut off at its starting size.
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

    public function testSinglePageThenEmptyPageEndsIteration(): void
    {
        // With no explicit limit, the iterator never trusts a page's reported total: it always
        // confirms the end with one more request, which here comes back empty.
        $transport = (new MockTransport())
            ->queueResponse(200, self::page(self::actors(1, 2, 3), 3, 0))
            ->queueResponse(200, self::page([], 3, 3));
        $ids = [];
        foreach ($this->client($transport)->actors()->iterate() as $actor) {
            $ids[] = $actor->getId();
        }
        self::assertSame(['a1', 'a2', 'a3'], $ids);
        self::assertSame(2, $transport->callCount());
        self::assertStringContainsString('offset=3', (string) $transport->received[1]->getUri());
    }

    public function testOverReportedTotalTerminates(): void
    {
        // The API claims 10 items but only 3 exist; the iterator must stop, not loop forever. The
        // reported total is irrelevant either way: termination follows only from the empty follow-up
        // page.
        $transport = (new MockTransport())
            ->queueResponse(200, self::page(self::actors(1, 2, 3), 10, 0))
            ->queueResponse(200, self::page([], 10, 3)); // the follow-up page comes back empty
        $ids = [];
        foreach ($this->client($transport)->actors()->iterate() as $actor) {
            $ids[] = $actor->getId();
        }
        self::assertSame(['a1', 'a2', 'a3'], $ids);
        self::assertSame(2, $transport->callCount());
    }

    public function testGrowingCollectionIsNotCutOffAtItsStartingSize(): void
    {
        // The collection holds 2 items when the first page is read and a 3rd is added before the
        // follow-up page is fetched. With no explicit limit the iterator must pick it up instead of
        // stopping at the total the first page reported.
        $transport = (new MockTransport())
            ->queueResponse(200, self::page(self::actors(1, 2), 2, 0))
            ->queueResponse(200, self::page(self::actors(3), 3, 2))
            ->queueResponse(200, self::page([], 3, 3));
        $ids = [];
        foreach ($this->client($transport)->actors()->iterate() as $actor) {
            $ids[] = $actor->getId();
        }
        self::assertSame(['a1', 'a2', 'a3'], $ids);
        self::assertSame(3, $transport->callCount());
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
        // The dataset-items endpoint returns a bare array and reports pagination via headers. With no
        // explicit limit, a trailing empty page confirms the end instead of trusting the total header.
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
            ])
            ->queueResponse(200, Json::encode([]), [
                'X-Apify-Pagination-Total' => '3',
                'X-Apify-Pagination-Offset' => '3',
                'X-Apify-Pagination-Limit' => '2',
            ]);
        $values = [];
        foreach ($this->client($transport)->dataset('ds')->iterateItems(null, 2) as $item) {
            $values[] = $item['n'];
        }
        self::assertSame([1, 2, 3], $values);
        self::assertSame(3, $transport->callCount());
        self::assertStringContainsString('offset=2', (string) $transport->received[1]->getUri());
        self::assertStringContainsString('offset=3', (string) $transport->received[2]->getUri());
    }

    public function testDatasetIterateItemsAdvancesByScannedCountNotItemCount(): void
    {
        // With skipEmpty active, the API can scan more rows than it returns as items. The iterator
        // must advance (and terminate) by the scanned count (`X-Apify-Pagination-Count`), not by the
        // item count — otherwise it would re-request offset=2 (items returned so far) instead of
        // offset=3 (rows actually scanned), re-scanning row 2 and duplicating item 'n'=2.
        $transport = (new MockTransport())
            // Page 1 scans rows 0-2 (3 rows) but one is empty and skipped, so only 2 items come back.
            ->queueResponse(200, Json::encode([['n' => 0], ['n' => 1]]), [
                'X-Apify-Pagination-Total' => '6',
                'X-Apify-Pagination-Offset' => '0',
                'X-Apify-Pagination-Limit' => '3',
                'X-Apify-Pagination-Count' => '3',
            ])
            // Page 2 scans rows 3-5 (3 rows), none filtered.
            ->queueResponse(200, Json::encode([['n' => 3], ['n' => 4], ['n' => 5]]), [
                'X-Apify-Pagination-Total' => '6',
                'X-Apify-Pagination-Offset' => '3',
                'X-Apify-Pagination-Limit' => '3',
                'X-Apify-Pagination-Count' => '3',
            ])
            // Trailing page confirms there is nothing left to scan.
            ->queueResponse(200, Json::encode([]), [
                'X-Apify-Pagination-Total' => '6',
                'X-Apify-Pagination-Offset' => '6',
                'X-Apify-Pagination-Limit' => '3',
                'X-Apify-Pagination-Count' => '0',
            ]);
        $values = [];
        foreach ($this->client($transport)->dataset('ds')->iterateItems(new DatasetListItemsOptions(skipEmpty: true), 3) as $item) {
            $values[] = $item['n'];
        }
        self::assertSame([0, 1, 3, 4, 5], $values);
        self::assertSame(3, $transport->callCount());
        self::assertStringContainsString('offset=3', (string) $transport->received[1]->getUri());
        self::assertStringContainsString('offset=6', (string) $transport->received[2]->getUri());
    }

    public function testDatasetIterateItemsCapsScannedCountWhenUnwindInflatesItemsPastTheRequestedLimit(): void
    {
        // With unwind active, a page can return MORE items than rows it scanned (the opposite of
        // skipEmpty). Here the scanned-count header is also missing (e.g. stripped by a proxy), so the
        // iterator falls back to the (unwind-inflated) item count — which must be capped at the
        // requested per-page limit (2), since the server never scans more rows per page than that.
        // Without the cap, the iterator would advance by the raw item count (6) instead of the real row
        // count (2), overshooting to offset=6 and skipping rows 2-3's items entirely.
        $transport = (new MockTransport())
            // Page 1: rows 0-1 (2 rows, matching the requested limit), each unwound into 3 items.
            ->queueResponse(200, Json::encode([['n' => 0], ['n' => 1], ['n' => 2], ['n' => 3], ['n' => 4], ['n' => 5]]), [
                'X-Apify-Pagination-Total' => '4',
                'X-Apify-Pagination-Offset' => '0',
                'X-Apify-Pagination-Limit' => '2',
                // No X-Apify-Pagination-Count header.
            ])
            // Page 2 (must be requested at offset=2, not offset=6): rows 2-3, likewise unwound.
            ->queueResponse(200, Json::encode([['n' => 6], ['n' => 7], ['n' => 8], ['n' => 9], ['n' => 10], ['n' => 11]]), [
                'X-Apify-Pagination-Total' => '4',
                'X-Apify-Pagination-Offset' => '2',
                'X-Apify-Pagination-Limit' => '2',
            ])
            // Trailing page confirms the dataset (4 real rows) is exhausted.
            ->queueResponse(200, Json::encode([]), [
                'X-Apify-Pagination-Total' => '4',
                'X-Apify-Pagination-Offset' => '4',
                'X-Apify-Pagination-Limit' => '2',
            ]);
        $values = [];
        foreach ($this->client($transport)->dataset('ds')->iterateItems(new DatasetListItemsOptions(unwind: ['parts']), 2) as $item) {
            $values[] = $item['n'];
        }
        self::assertSame(range(0, 11), $values);
        self::assertSame(3, $transport->callCount());
        self::assertStringContainsString('offset=2', (string) $transport->received[1]->getUri());
        self::assertStringContainsString('offset=4', (string) $transport->received[2]->getUri());
    }

    public function testDatasetIterateItemsPreservesFilters(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, Json::encode([['n' => 1]]), [
                'X-Apify-Pagination-Total' => '1',
                'X-Apify-Pagination-Offset' => '0',
                'X-Apify-Pagination-Limit' => '1',
            ])
            ->queueResponse(200, Json::encode([]), [
                'X-Apify-Pagination-Total' => '1',
                'X-Apify-Pagination-Offset' => '1',
                'X-Apify-Pagination-Limit' => '1',
            ]);
        $it = $this->client($transport)->dataset('ds')->iterateItems(new DatasetListItemsOptions(fields: ['n'], clean: true));
        iterator_to_array($it);
        $uri = (string) $transport->received[0]->getUri();
        self::assertStringContainsString('fields=n', $uri);
        self::assertStringContainsString('clean=1', $uri);
    }

    /** Builds a cursor-paged request-queue list envelope ({@code {"data": {items, nextCursor}}}). */
    private static function requestsPage(?string $nextCursor, string ...$ids): string
    {
        return Json::encode(['data' => [
            'items' => array_map(static fn (string $id): array => ['id' => $id, 'url' => "https://e/$id"], $ids),
            'count' => count($ids),
            'limit' => 1000,
            'nextCursor' => $nextCursor,
        ]]);
    }

    public function testPaginateRequestsLimitZeroIteratesAll(): void
    {
        // limit=0 is a total cap of "unbounded": iterate every page, and never forward limit=0 as a
        // per-page cap (which would short-circuit the iteration after a single page).
        $transport = (new MockTransport())
            ->queueResponse(200, self::requestsPage('cursor2', 'r1', 'r2'))
            ->queueResponse(200, self::requestsPage(null, 'r3'));
        $ids = [];
        foreach ($this->client($transport)->requestQueue('rq')->paginateRequests(new PaginateRequestsOptions(limit: 0)) as $request) {
            $ids[] = $request->getId();
        }
        self::assertSame(['r1', 'r2', 'r3'], $ids);
        self::assertSame(2, $transport->callCount());
        self::assertStringNotContainsString('limit=0', (string) $transport->received[0]->getUri());
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

    public function testIterateKeysChunkSizeCapsPageSizeIndependentlyOfLimit(): void
    {
        // chunkSize bounds each request independently of the total cap: limit=5, chunkSize=2 pages
        // through 2, 2, then the remaining 1.
        $transport = (new MockTransport())
            ->queueResponse(200, self::keysPage(true, 'k2', 'k1', 'k2'))
            ->queueResponse(200, self::keysPage(true, 'k4', 'k3', 'k4'))
            ->queueResponse(200, self::keysPage(true, 'k5', 'k5'));
        $keys = [];
        foreach ($this->client($transport)->keyValueStore('kvs')->iterateKeys(new ListKeysOptions(limit: 5), 2) as $key) {
            $keys[] = $key->getKey();
        }
        self::assertSame(['k1', 'k2', 'k3', 'k4', 'k5'], $keys);
        self::assertSame(3, $transport->callCount());
        self::assertStringContainsString('limit=2', (string) $transport->received[0]->getUri());
        self::assertStringContainsString('limit=2', (string) $transport->received[1]->getUri());
        // The last page is capped by the remaining total (1), not chunkSize (2).
        self::assertStringContainsString('limit=1', (string) $transport->received[2]->getUri());
    }

    public function testIterateKeysChunkSizeWithoutLimitBoundsEveryPage(): void
    {
        // With no total limit, chunkSize alone still caps each page's requested size.
        $transport = (new MockTransport())
            ->queueResponse(200, self::keysPage(true, 'k2', 'k1', 'k2'))
            ->queueResponse(200, self::keysPage(false, null, 'k3'));
        $keys = [];
        foreach ($this->client($transport)->keyValueStore('kvs')->iterateKeys(null, 2) as $key) {
            $keys[] = $key->getKey();
        }
        self::assertSame(['k1', 'k2', 'k3'], $keys);
        self::assertStringContainsString('limit=2', (string) $transport->received[0]->getUri());
        self::assertStringContainsString('limit=2', (string) $transport->received[1]->getUri());
    }

    public function testActorEnvVarsIterateMakesExactlyOneRequest(): void
    {
        // The env-vars endpoint ignores offset/limit and always returns every item in one response.
        // The mock only has one response queued, so a regression that re-pages would fail outright
        // instead of looping or duplicating items.
        $transport = (new MockTransport())->queueResponse(200, self::page([
            ['name' => 'A', 'value' => '1'],
            ['name' => 'B', 'value' => '2'],
        ], 2, 0));
        $names = [];
        foreach ($this->client($transport)->actor('a1')->version('0.0')->envVars()->iterate() as $envVar) {
            $names[] = $envVar->getName();
        }
        self::assertSame(['A', 'B'], $names);
        self::assertSame(1, $transport->callCount());
    }

    public function testActorVersionsIterateMakesExactlyOneRequest(): void
    {
        // The versions endpoint likewise ignores pagination and returns everything in one response.
        $transport = (new MockTransport())->queueResponse(200, self::page([
            ['versionNumber' => '0.0'],
            ['versionNumber' => '0.1'],
        ], 2, 0));
        $numbers = [];
        foreach ($this->client($transport)->actor('a1')->versions()->iterate() as $version) {
            $numbers[] = $version->getVersionNumber();
        }
        self::assertSame(['0.0', '0.1'], $numbers);
        self::assertSame(1, $transport->callCount());
    }
}
