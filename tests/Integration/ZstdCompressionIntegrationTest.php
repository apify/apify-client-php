<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\ApifyClient;
use Apify\Client\Http\ZstdHttpCompressor;

/**
 * Sends zstd-compressed request bodies to the live API. Skipped without the PECL {@code zstd} extension, which
 * the {@code integration-zstd} CI job loads.
 */
final class ZstdCompressionIntegrationTest extends IntegrationTestCase
{
    /** A JSON body well above the compression threshold. High-entropy padding keeps it large after compression. */
    private static function largeJson(): string
    {
        $json = json_encode(['hello' => 'world', 'padding' => bin2hex(random_bytes(2048))]);
        self::assertIsString($json);
        return $json;
    }

    private function requireZstdClient(): ApifyClient
    {
        if (!ZstdHttpCompressor::isAvailable()) {
            self::markTestSkipped('the PECL zstd extension is not loaded');
        }
        return $this->requireClient('zstd');
    }

    public function testKeyValueStoreRecordIsStoredFromAZstdBody(): void
    {
        $client = $this->requireZstdClient();
        $store = $client->keyValueStores()->getOrCreate(self::uniqueName('kvs-zstd'));
        try {
            $kvs = $client->keyValueStore((string) $store->getId());
            $json = self::largeJson();

            $kvs->setRecord('OUTPUT', $json, 'application/json');

            $record = $kvs->getRecord('OUTPUT');
            self::assertNotNull($record);
            self::assertSame($json, $record->getValue());
        } finally {
            $client->keyValueStore((string) $store->getId())->delete();
        }
    }

    public function testDatasetItemsAreStoredFromAZstdBody(): void
    {
        $client = $this->requireZstdClient();
        $dataset = $client->datasets()->getOrCreate(self::uniqueName('ds-zstd'));
        try {
            $items = $client->dataset((string) $dataset->getId());
            $padding = bin2hex(random_bytes(2048));

            $items->pushItems([['n' => 1, 'padding' => $padding], ['n' => 2, 'padding' => $padding]]);

            self::waitForDatasetItemCount($items, 2);
            $stored = $items->listItems()->getItems();
            self::assertSame([1, 2], array_column($stored, 'n'));
            self::assertSame($padding, $stored[0]['padding']);
        } finally {
            $client->dataset((string) $dataset->getId())->delete();
        }
    }
}
