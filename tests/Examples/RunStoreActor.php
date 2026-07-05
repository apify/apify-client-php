<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Examples;

use Apify\Client\ApifyClient;
use Apify\Client\Options\DatasetListItemsOptions;

/** Run a store Actor and read its default dataset. */
final class RunStoreActor
{
    public static function run(ApifyClient $client): void
    {
        $run = $client->actor('apify/hello-world')->call(null, null, 120);
        $items = $client->dataset((string) $run->getDefaultDatasetId())->listItems(new DatasetListItemsOptions());
        echo 'Item count: ' . $items->getCount() . PHP_EOL;
    }
}
