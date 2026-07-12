<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Examples;

use Apify\Client\ApifyClient;
use Apify\Client\Options\StoreListOptions;

/** Lazy iteration of Store Actors using the convenience iterator. */
final class IterateStore
{
    public static function run(ApifyClient $client): void
    {
        $shown = 0;
        // The second argument is the per-page (chunk) size; the iterator fetches pages lazily as we
        // consume items. StoreListOptions::limit (unset here) would cap the total across all pages.
        foreach ($client->store()->iterate(new StoreListOptions(), 10) as $item) {
            echo $item->getName() . PHP_EOL;
            if (++$shown >= 5) {
                break;
            }
        }
    }
}
