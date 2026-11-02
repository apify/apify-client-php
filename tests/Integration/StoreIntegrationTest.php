<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Options\StoreListOptions;

final class StoreIntegrationTest extends IntegrationTestCase
{
    public function testListStore(): void
    {
        $client = $this->requireClient();
        $page = $client->store()->list(new StoreListOptions(limit: 5));
        self::assertLessThanOrEqual(5, count($page->getItems()));
    }

    public function testListStoreWithInputSchema(): void
    {
        $client = $this->requireClient();
        $withSchema = $client->store()->list(new StoreListOptions(search: 'website content crawler', limit: 10, includeInputSchema: true));
        self::assertNotEmpty($withSchema->getItems());
        $withoutSchema = $client->store()->list(new StoreListOptions(search: 'website content crawler', limit: 10));
        foreach ($withoutSchema->getItems() as $item) {
            self::assertNull($item->get('inputSchema'), 'the input schema is only returned on request');
        }
        $schemas = array_filter(
            array_map(static fn ($item) => $item->get('inputSchema'), $withSchema->getItems()),
            static fn ($schema) => $schema !== null,
        );
        self::assertNotEmpty($schemas, 'at least one Store Actor carries its input schema');
    }

    public function testIterateStore(): void
    {
        $client = $this->requireClient();
        $count = 0;
        // chunkSize=5 is the per-page size; with no limit the iterator keeps fetching pages until we
        // break, proving pagination is followed across more than two pages.
        foreach ($client->store()->iterate(new StoreListOptions(), 5) as $item) {
            self::assertNotNull($item->getId());
            self::assertNotSame('', $item->getId());
            if (++$count >= 12) {
                break;
            }
        }
        self::assertGreaterThanOrEqual(12, $count, 'expected to iterate at least 12 store actors');
    }

    public function testIterateStoreRespectsTotalLimit(): void
    {
        $client = $this->requireClient();
        $count = 0;
        // limit is a total-item cap across all pages: iteration must stop at 3 even with tiny pages.
        foreach ($client->store()->iterate(new StoreListOptions(limit: 3), 1) as $item) {
            self::assertNotNull($item->getId());
            $count++;
        }
        self::assertSame(3, $count, 'limit must cap the total number of iterated items');
    }
}
