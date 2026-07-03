<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Examples;

use Apify\Client\ApifyClient;
use Apify\Client\Model\RequestQueueRequest;
use Apify\Client\Options\DatasetListItemsOptions;

/** Each storage: create, push data, read data back. */
final class Storages
{
    public static function run(ApifyClient $client): void
    {
        // Dataset
        $dataset = $client->datasets()->getOrCreate('php-example-ds-' . bin2hex(random_bytes(4)));
        try {
            $client->dataset((string) $dataset->getId())->pushItems([['hello' => 'world']]);
            $items = $client->dataset((string) $dataset->getId())->listItems(new DatasetListItemsOptions());
            echo 'Dataset items: ' . $items->getCount() . PHP_EOL;
        } finally {
            $client->dataset((string) $dataset->getId())->delete();
        }

        // Key-value store
        $store = $client->keyValueStores()->getOrCreate('php-example-kvs-' . bin2hex(random_bytes(4)));
        try {
            $client->keyValueStore((string) $store->getId())->setRecordJson('OUTPUT', ['answer' => 42]);
            $record = $client->keyValueStore((string) $store->getId())->getRecord('OUTPUT');
            echo 'KVS record: ' . ($record?->getValue() ?? '') . PHP_EOL;
        } finally {
            $client->keyValueStore((string) $store->getId())->delete();
        }

        // Request queue
        $queue = $client->requestQueues()->getOrCreate('php-example-rq-' . bin2hex(random_bytes(4)));
        try {
            $client->requestQueue((string) $queue->getId())
                ->addRequest(new RequestQueueRequest('https://example.com', 'example'));
            $head = $client->requestQueue((string) $queue->getId())->listHead(10);
            echo 'Queue head size: ' . count($head->getItems()) . PHP_EOL;
        } finally {
            $client->requestQueue((string) $queue->getId())->delete();
        }
    }
}
