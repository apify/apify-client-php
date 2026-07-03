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
