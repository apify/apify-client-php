<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Examples;

use Apify\Client\ApifyClient;
use Apify\Client\Options\DatasetListItemsOptions;
use Apify\Client\Options\LastRunOptions;

/** Start a run, wait, then fetch the Actor's last run and its storages. */
final class RunAndLastRunStorages
{
    public static function run(ApifyClient $client): void
    {
        $client->actor('apify/hello-world')->call(null, null, 120);
        $last = $client->actor('apify/hello-world')->lastRun(new LastRunOptions(status: 'SUCCEEDED'))->get();
        if ($last !== null) {
            $client->dataset((string) $last->getDefaultDatasetId())->listItems(new DatasetListItemsOptions());
            $client->keyValueStore((string) $last->getDefaultKeyValueStoreId())->getRecord('OUTPUT');
            echo 'Last run: ' . $last->getId() . PHP_EOL;
        }
    }
}
