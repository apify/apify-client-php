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
        // Start the run, then wait for it to finish.
        $started = $client->actor('apify/hello-world')->start();
        $client->run((string) $started->getId())->waitForFinish(120);

        // Fetch the Actor's last successful run and read its storages through the run-nested
        // convenience accessors, which resolve the last run's dataset, key-value store and
        // request queue without needing their individual IDs.
        $lastRun = $client->actor('apify/hello-world')->lastRun(new LastRunOptions(status: 'SUCCEEDED'));
        $last = $lastRun->get();
        if ($last !== null) {
            $lastRun->dataset()->listItems(new DatasetListItemsOptions());
            $lastRun->keyValueStore()->getRecord('OUTPUT');
            $lastRun->requestQueue()->listHead(10);
            echo 'Last run: ' . $last->getId() . PHP_EOL;
        }
    }
}
