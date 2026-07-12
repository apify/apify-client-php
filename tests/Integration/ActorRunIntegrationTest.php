<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Options\DatasetListItemsOptions;
use Apify\Client\Options\LastRunOptions;
use Apify\Client\Options\ListOptions;
use Apify\Client\Options\RunListOptions;

final class ActorRunIntegrationTest extends IntegrationTestCase
{
    public function testListRuns(): void
    {
        $client = $this->requireClient();
        $page = $client->runs()->list(new ListOptions(limit: 5), new RunListOptions());
        self::assertLessThanOrEqual(5, count($page->getItems()));
        self::assertSame(count($page->getItems()), $page->getCount());
        self::assertGreaterThanOrEqual(count($page->getItems()), $page->getTotal());
    }

    public function testIterateRuns(): void
    {
        $client = $this->requireClient();
        // Ensure at least one run exists for this account, then iterate with a small total cap and a
        // page size that forces multi-page paging. Runs are shared account state, so the test asserts
        // the cap and shape rather than an exact set, keeping it parallel-safe.
        $client->actor('apify/hello-world')->call(null, null, 120);
        $count = 0;
        foreach ($client->runs()->iterate(new ListOptions(limit: 3), new RunListOptions(), 2) as $run) {
            self::assertNotNull($run->getId());
            self::assertNotSame('', $run->getId());
            $count++;
        }
        self::assertGreaterThanOrEqual(1, $count);
        self::assertLessThanOrEqual(3, $count, 'the total-item cap (limit) must bound iteration');
    }

    public function testRunActorAndReadOutputs(): void
    {
        $client = $this->requireClient();
        $run = $client->actor('apify/hello-world')->call(null, null, 120);
        self::assertSame('SUCCEEDED', $run->getStatus());

        self::assertNotNull($client->run((string) $run->getId())->get());

        $log = $client->run((string) $run->getId())->log()->get();
        self::assertNotNull($log);
        self::assertNotSame('', $log);

        $client->run((string) $run->getId())->dataset()->listItems(new DatasetListItemsOptions());
        $client->run((string) $run->getId())->keyValueStore()->getRecord('OUTPUT');
    }

    public function testLastRunAccess(): void
    {
        $client = $this->requireClient();
        $client->actor('apify/hello-world')->call(null, null, 120);

        $lastRun = $client->actor('apify/hello-world')->lastRun(new LastRunOptions(status: 'SUCCEEDED'))->get();
        self::assertNotNull($lastRun);
        self::assertSame('SUCCEEDED', $lastRun->getStatus());

        $byOrigin = $client->actor('apify/hello-world')
            ->lastRun(new LastRunOptions(status: 'SUCCEEDED', origin: 'API'))
            ->get();
        self::assertNotNull($byOrigin);
        self::assertSame('SUCCEEDED', $byOrigin->getStatus());
    }
}
