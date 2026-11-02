<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Internal\UrlNormalizer;
use Apify\Client\Options\DatasetListItemsOptions;
use Apify\Client\Options\LastRunOptions;
use Apify\Client\Options\ListOptions;
use Apify\Client\Options\RunIterateDatasetItemsOptions;
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

    public function testIterateDatasetItemsOfAFinishedRunYieldsEveryItem(): void
    {
        $client = $this->requireClient();
        $run = $client->actor('apify/hello-world')->call(null, null, 120);
        self::assertSame('SUCCEEDED', $run->getStatus());
        $runClient = $client->run((string) $run->getId());

        $expected = iterator_to_array($runClient->dataset()->iterateItems(), false);
        $live = iterator_to_array($runClient->iterateDatasetItems(new RunIterateDatasetItemsOptions(chunkSize: 1, pollIntervalSecs: 1)), false);

        self::assertSame($expected, $live);
    }

    public function testIterateDatasetItemsOfARunPushingItemsReadsThemWhileItRuns(): void
    {
        $client = $this->requireClient();
        $actor = $client->actor('apify/hello-world');
        $run = $actor->start();
        $items = iterator_to_array($client->run((string) $run->getId())->iterateDatasetItems(new RunIterateDatasetItemsOptions(pollIntervalSecs: 1)), false);

        // The iteration only returns once the run has finished and the dataset is drained, so it has
        // seen everything the run pushed.
        $finished = $client->run((string) $run->getId())->get();
        self::assertNotNull($finished);
        self::assertTrue($finished->isTerminal());
        // The dataset's `itemCount` lags the pushed items by a few seconds, so compare with a read of the items.
        $all = iterator_to_array($client->run((string) $run->getId())->dataset()->iterateItems(), false);
        self::assertGreaterThanOrEqual(1, count($items));
        self::assertSame($all, $items);
    }

    public function testRunsListAcceptsTheDeploymentFilter(): void
    {
        $client = $this->requireClient();
        $filter = new RunListOptions(status: ['RUNNING'], deployment: ['SINGLE_TENANT']);
        $page = $client->runs()->list(new ListOptions(limit: 5), $filter);
        self::assertLessThanOrEqual(5, count($page->getItems()));
        $page = $client->actor('apify/hello-world')->runs()->list(new ListOptions(limit: 5), $filter);
        self::assertLessThanOrEqual(5, count($page->getItems()));
    }

    public function testRunUrlFieldsAreNormalized(): void
    {
        $client = $this->requireClient();
        $run = $client->actor('apify/hello-world')->call(null, null, 120);

        foreach (['consoleUrl', 'containerUrl'] as $field) {
            $url = $run->get($field);
            if (is_string($url)) {
                self::assertSame(UrlNormalizer::normalize($url), $url, "$field must be returned normalized");
            }
        }
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
