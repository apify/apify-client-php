<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Options\DatasetDownloadOptions;
use Apify\Client\Options\DatasetListItemsOptions;
use Apify\Client\Options\DownloadItemsFormat;
use Apify\Client\Options\StorageListOptions;

final class DatasetIntegrationTest extends IntegrationTestCase
{
    public function testListDatasets(): void
    {
        $client = $this->requireClient();
        $page = $client->datasets()->list(new StorageListOptions(limit: 5));
        self::assertLessThanOrEqual(5, count($page->getItems()));
        self::assertSame(count($page->getItems()), $page->getCount());
        self::assertGreaterThanOrEqual(count($page->getItems()), $page->getTotal());
    }

    public function testGetDataset(): void
    {
        $client = $this->requireClient();
        $ds = $client->datasets()->getOrCreate(self::uniqueName('ds-get'));
        try {
            $got = $client->dataset((string) $ds->getId())->get();
            self::assertNotNull($got);
            self::assertSame($ds->getId(), $got->getId());
        } finally {
            $client->dataset((string) $ds->getId())->delete();
        }
    }

    public function testIterateDatasets(): void
    {
        $client = $this->requireClient();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = (string) $client->datasets()->getOrCreate(self::uniqueName('iter-ds'))->getId();
        }
        try {
            // Retried with backoff: dataset listing is eventually consistent under concurrent load.
            self::assertEventuallyIterated($ids, static function () use ($client): array {
                $seen = [];
                foreach ($client->datasets()->iterate(new StorageListOptions(desc: true), 2) as $dataset) {
                    $seen[(string) $dataset->getId()] = true;
                }
                return $seen;
            }, 'dataset');
        } finally {
            foreach ($ids as $id) {
                $client->dataset($id)->delete();
            }
        }
    }

    public function testIterateDatasetItems(): void
    {
        $client = $this->requireClient();
        $ds = $client->datasets()->getOrCreate(self::uniqueName('iter-items'));
        try {
            $dataset = $client->dataset((string) $ds->getId());
            $dataset->pushItems([['n' => 0], ['n' => 1], ['n' => 2], ['n' => 3], ['n' => 4]]);

            // The dataset's item total is computed asynchronously and can briefly lag a write.
            // iterateItems() pages by the reported total (matching the reference client), so wait for
            // the count to settle before iterating; otherwise a stale total would stop it early.
            $deadline = microtime(true) + 30.0;
            while (
                $dataset->listItems(new DatasetListItemsOptions())->getTotal() < 5
                && microtime(true) < $deadline
            ) {
                usleep(500_000);
            }

            $values = [];
            // chunkSize=2 across 5 items => three pages (2, 2, 1).
            foreach ($dataset->iterateItems(new DatasetListItemsOptions(), 2) as $item) {
                $values[] = $item['n'];
            }
            sort($values);
            self::assertSame([0, 1, 2, 3, 4], $values);
        } finally {
            $client->dataset((string) $ds->getId())->delete();
        }
    }

    public function testDatasetCrudFlow(): void
    {
        $client = $this->requireClient();
        $ds = $client->datasets()->getOrCreate(self::uniqueName('ds-crud'));
        try {
            $dataset = $client->dataset((string) $ds->getId());
            self::assertNotNull($dataset->get());

            $dataset->pushItems([
                ['url' => 'https://a.com', 'n' => 1],
                ['url' => 'https://b.com', 'n' => 2],
                ['url' => 'https://c.com', 'n' => 3],
            ]);

            $page = $dataset->listItems(new DatasetListItemsOptions());
            self::assertSame(3, $page->getCount());
            self::assertCount(3, $page->getItems());
            self::assertSame(1, $page->getItems()[0]['n']);
            // isDesc() is sourced from the server's X-Apify-Pagination-Desc response header.
            self::assertFalse($page->isDesc());

            $csv = $dataset->downloadItems(DownloadItemsFormat::CSV, new DatasetDownloadOptions(bom: true));
            self::assertStringContainsString('url', $csv);

            $url = $dataset->createItemsPublicUrl(new DatasetListItemsOptions());
            self::assertNotSame('', $url);

            $dataset->getStatistics();

            $updated = $dataset->update(['name' => self::uniqueName('ds-renamed')]);
            self::assertNotNull($updated->getName());
            self::assertNotSame('', $updated->getName());
        } finally {
            $client->dataset((string) $ds->getId())->delete();
        }
    }
}
