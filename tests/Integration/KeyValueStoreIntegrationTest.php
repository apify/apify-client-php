<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Options\GetRecordOptions;
use Apify\Client\Options\ListKeysOptions;
use Apify\Client\Options\StorageListOptions;
use GuzzleHttp\Client as Guzzle;

final class KeyValueStoreIntegrationTest extends IntegrationTestCase
{
    public function testListKeyValueStores(): void
    {
        $client = $this->requireClient();
        $page = $client->keyValueStores()->list(new StorageListOptions(limit: 5));
        self::assertLessThanOrEqual(5, count($page->getItems()));
        self::assertSame(count($page->getItems()), $page->getCount());
        self::assertGreaterThanOrEqual(count($page->getItems()), $page->getTotal());
    }

    public function testGetKeyValueStore(): void
    {
        $client = $this->requireClient();
        $store = $client->keyValueStores()->getOrCreate(self::uniqueName('kvs-get'));
        try {
            $got = $client->keyValueStore((string) $store->getId())->get();
            self::assertNotNull($got);
            self::assertSame($store->getId(), $got->getId());
        } finally {
            $client->keyValueStore((string) $store->getId())->delete();
        }
    }

    public function testIterateKeyValueStores(): void
    {
        $client = $this->requireClient();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = (string) $client->keyValueStores()->getOrCreate(self::uniqueName('iter-kvs'))->getId();
        }
        try {
            // Retried with backoff: store listing is eventually consistent under concurrent load.
            self::assertEventuallyIterated($ids, static function () use ($client): array {
                $seen = [];
                foreach ($client->keyValueStores()->iterate(new StorageListOptions(desc: true), 2) as $store) {
                    $seen[(string) $store->getId()] = true;
                }
                return $seen;
            }, 'store');
        } finally {
            foreach ($ids as $id) {
                $client->keyValueStore($id)->delete();
            }
        }
    }

    public function testIterateKeys(): void
    {
        $client = $this->requireClient();
        $store = $client->keyValueStores()->getOrCreate(self::uniqueName('iter-keys'));
        try {
            $kvs = $client->keyValueStore((string) $store->getId());
            $expected = [];
            for ($i = 0; $i < 5; $i++) {
                $key = sprintf('key-%02d', $i);
                $kvs->setRecordJson($key, ['n' => $i]);
                $expected[] = $key;
            }
            $seen = [];
            // limit as a total cap of 5; the store's cursor pagination threads exclusiveStartKey.
            foreach ($kvs->iterateKeys(new ListKeysOptions(limit: 5)) as $key) {
                $seen[] = (string) $key->getKey();
            }
            sort($seen);
            self::assertSame($expected, $seen);
        } finally {
            $client->keyValueStore((string) $store->getId())->delete();
        }
    }

    public function testRecordKeyWithSpecialChars(): void
    {
        $client = $this->requireClient();
        $store = $client->keyValueStores()->getOrCreate(self::uniqueName('kvs-special'));
        try {
            $kvs = $client->keyValueStore((string) $store->getId());
            $key = "weird-key!'()";
            $kvs->setRecordJson($key, ['ok' => true]);
            self::assertTrue($kvs->recordExists($key));
            self::assertNotNull($kvs->getRecord($key));
            $kvs->deleteRecord($key);
        } finally {
            $client->keyValueStore((string) $store->getId())->delete();
        }
    }

    public function testKeyValueStoreCrudFlow(): void
    {
        $client = $this->requireClient();
        $store = $client->keyValueStores()->getOrCreate(self::uniqueName('kvs-crud'));
        try {
            $kvs = $client->keyValueStore((string) $store->getId());
            self::assertNotNull($kvs->get());
            $kvs->setRecordJson('OUTPUT', ['hello' => 'world']);
            self::assertTrue($kvs->recordExists('OUTPUT'));
            $record = $kvs->getRecord('OUTPUT');
            self::assertNotNull($record);
            self::assertStringContainsString('world', $record->getValue());
            $kvs->getRecord('OUTPUT', new GetRecordOptions(attachment: false));
            $keys = $kvs->listKeys(new ListKeysOptions());
            self::assertNotEmpty($keys->getItems());
            $kvs->update(['name' => self::uniqueName('kvs-renamed')]);
            $kvs->deleteRecord('OUTPUT');
        } finally {
            $client->keyValueStore((string) $store->getId())->delete();
        }
    }

    public function testSetRecordWithContentEncodingStoresPreCompressedValueAsIs(): void
    {
        $client = $this->requireClient();
        $store = $client->keyValueStores()->getOrCreate(self::uniqueName('kvs-gzip'));
        try {
            $kvs = $client->keyValueStore((string) $store->getId());
            // High-entropy padding (hex of random bytes), so it survives gzip compression large
            // enough to stay above the client's own compression threshold (see assertion below) —
            // repetitive padding would compress away almost entirely.
            $original = json_encode(['hello' => 'world', 'n' => 42, 'padding' => bin2hex(random_bytes(2048))]);
            self::assertIsString($original);
            $gzipped = gzencode($original);
            self::assertIsString($gzipped);
            // A gzipped JSON payload is itself well over the 1 KiB auto-compression threshold, so
            // this also proves the client's own compression genuinely backed off: if it had gzipped
            // the already-gzipped bytes a second time, the API would receive double-compressed gzip
            // under a single Content-Encoding: gzip header, decode it once, and store the *inner*
            // gzip bytes as the record's "JSON" — which could never decode back to the original value
            // below.
            self::assertGreaterThan(1024, strlen($gzipped));

            $kvs->setRecord('precompressed.json', $gzipped, 'application/json', contentEncoding: 'gzip');

            // The live API decompresses a Content-Encoding-tagged upload on ingest and serves the
            // record's plain bytes back out (it does not echo Content-Encoding on GET, nor keep the
            // compressed bytes verbatim), so the round-trip is verified through the parsed value
            // rather than a byte-for-byte comparison against $gzipped.
            $record = $kvs->getRecord('precompressed.json');
            self::assertNotNull($record);
            self::assertSame($original, $record->getValue());
            self::assertStringContainsString('application/json', (string) $record->getContentType());
        } finally {
            $client->keyValueStore((string) $store->getId())->delete();
        }
    }

    public function testRecordPublicUrlIsFetchable(): void
    {
        $client = $this->requireClient();
        $store = $client->keyValueStores()->getOrCreate(self::uniqueName('kvs-pub'));
        try {
            $kvs = $client->keyValueStore((string) $store->getId());
            $kvs->setRecordJson('OUTPUT', ['pub' => true]);
            $url = $kvs->getRecordPublicUrl('OUTPUT');
            self::assertNotSame('', $url);

            $response = (new Guzzle())->get($url, ['http_errors' => false]);
            self::assertLessThan(300, $response->getStatusCode(), 'expected success fetching public url');
        } finally {
            $client->keyValueStore((string) $store->getId())->delete();
        }
    }
}
