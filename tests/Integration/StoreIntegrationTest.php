<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Options\StoreListOptions;

final class StoreIntegrationTest extends IntegrationTestCase
{
    public function testListStore(): void
    {
        $client = $this->requireClient();
        $page = $client->store()->list(new StoreListOptions(limit: 5));
        self::assertLessThanOrEqual(5, count($page->getItems()));
    }

    public function testIterateStore(): void
    {
        $client = $this->requireClient();
        $count = 0;
        foreach ($client->store()->iterate(new StoreListOptions(limit: 5)) as $item) {
            self::assertNotNull($item->getId());
            self::assertNotSame('', $item->getId());
            if (++$count >= 12) {
                break;
            }
        }
        self::assertGreaterThanOrEqual(12, $count, 'expected to iterate at least 12 store actors');
    }
}
