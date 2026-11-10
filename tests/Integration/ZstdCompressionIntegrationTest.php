<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\ApifyClient;
use Apify\Client\Http\GuzzleHttpClient;
use Apify\Client\Http\HttpClientInterface;
use Apify\Client\Http\ZstdHttpCompressor;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends zstd-compressed request bodies to the live API. Skipped without the PECL {@code zstd} extension, which
 * the {@code integration-zstd} CI job loads.
 */
final class ZstdCompressionIntegrationTest extends IntegrationTestCase
{
    /** @var list<string> the {@code Content-Encoding} of every request that was sent, in order */
    private array $encodings = [];

    /** A JSON body above the compression threshold. Random padding keeps it from being small to begin with. */
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
        $encodings = &$this->encodings;
        $recorder = new class (new GuzzleHttpClient(), $encodings) implements HttpClientInterface {
            /** @param list<string> $encodings */
            public function __construct(private HttpClientInterface $inner, private array &$encodings)
            {
            }

            public function send(RequestInterface $request, float $timeoutSecs): ResponseInterface
            {
                $this->encodings[] = $request->getHeaderLine('Content-Encoding');
                return $this->inner->send($request, $timeoutSecs);
            }

            public function sendStreaming(RequestInterface $request, float $timeoutSecs): ResponseInterface
            {
                return $this->inner->sendStreaming($request, $timeoutSecs);
            }
        };
        return $this->requireClient('zstd', $recorder);
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
            self::assertContains('zstd', $this->encodings);
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
            self::assertContains('zstd', $this->encodings);
        } finally {
            $client->dataset((string) $dataset->getId())->delete();
        }
    }
}
