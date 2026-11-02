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

    public function testReadRunStoragesAndLogById(): void
    {
        $client = $this->requireClient();
        $run = $client->actor('apify/hello-world')->call(null, null, 120);
        $runClient = $client->run((string) $run->getId());

        $dataset = $runClient->dataset()->get();
        self::assertNotNull($dataset);
        self::assertSame($run->getDefaultDatasetId(), $dataset->getId());
        self::assertNotNull($runClient->dataset()->getStatistics());

        $store = $runClient->keyValueStore()->get();
        self::assertNotNull($store);
        self::assertSame($run->getDefaultKeyValueStoreId(), $store->getId());

        $queue = $runClient->requestQueue()->get();
        self::assertNotNull($queue);
        self::assertSame($run->getDefaultRequestQueueId(), $queue->getId());

        $log = $client->log((string) $run->getId())->get();
        self::assertNotNull($log);
        self::assertNotSame('', $log);
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

    public function testIterateDatasetItemsReadsItemsWhileTheRunIsStillRunning(): void
    {
        $client = $this->requireClient();
        // An Actor that pushes one item every ten seconds, three times, straight through the API
        // (the Actor runtime provides the token, the dataset ID and the public API base URL).
        $script = <<<'JS'
            const base = (process.env.APIFY_API_PUBLIC_BASE_URL || 'https://api.apify.com').replace(/\/$/, '');
            const url = `${base}/v2/datasets/${process.env.APIFY_DEFAULT_DATASET_ID}/items?token=${process.env.APIFY_TOKEN}`;
            const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
            (async () => {
                for (let n = 1; n <= 3; n++) {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: { 'content-type': 'application/json' },
                        body: JSON.stringify({ n }),
                    });
                    if (!response.ok) throw new Error(`push failed: ${response.status}`);
                    await sleep(10000);
                }
            })();
            JS;
        $definition = self::minimalActor(self::uniqueName('live'));
        $definition['versions'][0]['sourceFiles'][1]['content'] = $script;
        $actor = $client->actors()->create($definition);
        try {
            $actorClient = $client->actor((string) $actor->getId());
            $build = $actorClient->build('0.0');
            self::assertSame('SUCCEEDED', $client->build((string) $build->getId())->waitForFinish(300)->getStatus());

            $run = $actorClient->start();
            $runClient = $client->run((string) $run->getId());
            $statusWhenReceived = [];
            $items = [];
            foreach ($runClient->iterateDatasetItems(new RunIterateDatasetItemsOptions(pollIntervalSecs: 1)) as $item) {
                $items[] = $item['n'];
                $statusWhenReceived[] = $runClient->get()?->getStatus();
            }

            self::assertSame([1, 2, 3], $items);
            // The first item reached the caller while the Actor was still sleeping before its last push.
            self::assertContains($statusWhenReceived[0], ['READY', 'RUNNING']);
            self::assertTrue($runClient->get()?->isTerminal());
        } finally {
            $client->actor((string) $actor->getId())->delete();
        }
    }

    public function testRunsListAcceptsTheDeploymentFilter(): void
    {
        $client = $this->requireClient();
        $filter = new RunListOptions(status: ['RUNNING'], deployment: ['SINGLE_TENANT']);
        // `hello-world` runs are not Actor Standby runs and none is running, so a deployment filter that the API
        // applies leaves nothing, where the same listing without it returns the finished runs.
        $client->actor('apify/hello-world')->call(null, null, 120);
        $unfiltered = new RunListOptions(status: ['SUCCEEDED']);
        self::assertNotEmpty($client->runs()->list(new ListOptions(limit: 5), $unfiltered)->getItems());
        self::assertSame([], $client->runs()->list(new ListOptions(limit: 5), $filter)->getItems());
        self::assertSame([], $client->actor('apify/hello-world')->runs()->list(new ListOptions(limit: 5), $filter)->getItems());
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
