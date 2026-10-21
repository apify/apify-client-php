<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\ApifyClient;
use Apify\Client\Options\ListOptions;

final class WebhookIntegrationTest extends IntegrationTestCase
{
    /**
     * Runs {@code $body} with the ID of a task that only this test owns, deleting the task afterwards.
     * A webhook conditioned on it can fire only for runs of that task, which nothing else starts. The
     * API rejects an ad hoc webhook whose run does not exist, so these tests use persistent webhooks.
     *
     * @param callable(string): void $body
     */
    private function withConditionTask(ApifyClient $client, callable $body): void
    {
        $task = $client->tasks()->create([
            'actId' => 'apify/hello-world',
            'name' => self::uniqueName('webhook-task'),
            'options' => ['build' => 'latest', 'memoryMbytes' => 256, 'timeoutSecs' => 60],
            'input' => ['message' => 'hello'],
        ]);
        $taskId = (string) $task->getId();
        try {
            $body($taskId);
        } finally {
            $client->task($taskId)->delete();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function webhookDef(string $taskId, string $url): array
    {
        return [
            'eventTypes' => ['ACTOR.RUN.SUCCEEDED'],
            'condition' => ['actorTaskId' => $taskId],
            'requestUrl' => $url,
        ];
    }

    public function testListWebhooks(): void
    {
        $client = $this->requireClient();
        $page = $client->webhooks()->list(new ListOptions(limit: 5));
        self::assertLessThanOrEqual(5, count($page->getItems()));
        self::assertSame(count($page->getItems()), $page->getCount());
        self::assertGreaterThanOrEqual(count($page->getItems()), $page->getTotal());
    }

    public function testListWebhookDispatches(): void
    {
        $client = $this->requireClient();
        $page = $client->webhookDispatches()->list(new ListOptions(limit: 5));
        self::assertLessThanOrEqual(5, count($page->getItems()));
        self::assertSame(count($page->getItems()), $page->getCount());
        self::assertGreaterThanOrEqual(count($page->getItems()), $page->getTotal());
    }

    public function testIterateWebhooks(): void
    {
        $client = $this->requireClient();
        $this->withConditionTask($client, static function (string $taskId) use ($client): void {
            $ids = [];
            try {
                for ($i = 0; $i < 3; $i++) {
                    $ids[] = (string) $client->webhooks()->create(self::webhookDef($taskId, 'https://example.com/iter-' . $i))->getId();
                }
                // Retried with backoff: webhook listing is eventually consistent under concurrent load.
                self::assertEventuallyIterated($ids, static function () use ($client): array {
                    $seen = [];
                    foreach ($client->webhooks()->iterate(new ListOptions(desc: true), 2) as $webhook) {
                        $seen[(string) $webhook->getId()] = true;
                    }
                    return $seen;
                }, 'webhook');
            } finally {
                foreach ($ids as $id) {
                    $client->webhook($id)->delete();
                }
            }
        });
    }

    public function testIterateWebhookDispatches(): void
    {
        $client = $this->requireClient();
        $this->withConditionTask($client, static function (string $taskId) use ($client): void {
            $wh = $client->webhooks()->create(self::webhookDef($taskId, 'https://example.com/dispatch-iter'));
            try {
                // test() synchronously creates an ad-hoc dispatch listed under the webhook.
                $dispatch = $client->webhook((string) $wh->getId())->test();
                // Retried with backoff: dispatch listing is eventually consistent under concurrent load.
                self::assertEventuallyIterated([(string) $dispatch->getId()], static function () use ($client, $wh): array {
                    $seen = [];
                    foreach ($client->webhook((string) $wh->getId())->dispatches()->iterate(new ListOptions(), 2) as $d) {
                        $seen[(string) $d->getId()] = true;
                    }
                    return $seen;
                }, 'dispatch');
            } finally {
                $client->webhook((string) $wh->getId())->delete();
            }
        });
    }

    public function testGetWebhook(): void
    {
        $client = $this->requireClient();
        $this->withConditionTask($client, static function (string $taskId) use ($client): void {
            $wh = $client->webhooks()->create(self::webhookDef($taskId, 'https://example.com/webhook'));
            try {
                $got = $client->webhook((string) $wh->getId())->get();
                self::assertNotNull($got);
                self::assertSame($wh->getId(), $got->getId());
            } finally {
                $client->webhook((string) $wh->getId())->delete();
            }
        });
    }

    public function testGetWebhookDispatch(): void
    {
        $client = $this->requireClient();
        $this->withConditionTask($client, static function (string $taskId) use ($client): void {
            $wh = $client->webhooks()->create(self::webhookDef($taskId, 'https://example.com/webhook'));
            try {
                $dispatch = $client->webhook((string) $wh->getId())->test();
                $got = $client->webhookDispatch((string) $dispatch->getId())->get();
                self::assertNotNull($got);
                self::assertSame($dispatch->getId(), $got->getId());
            } finally {
                $client->webhook((string) $wh->getId())->delete();
            }
        });
    }

    public function testWebhookCrudFlow(): void
    {
        $client = $this->requireClient();
        $this->withConditionTask($client, static function (string $taskId) use ($client): void {
            $wh = $client->webhooks()->create(self::webhookDef($taskId, 'https://example.com/webhook'));
            try {
                $webhook = $client->webhook((string) $wh->getId());
                self::assertNotNull($webhook->get());
                $updated = $webhook->update(['requestUrl' => 'https://example.com/updated']);
                self::assertSame('https://example.com/updated', $updated->getRequestUrl());
                $webhook->dispatches()->list(new ListOptions());
                $webhook->test();
            } finally {
                $client->webhook((string) $wh->getId())->delete();
            }
        });
    }
}
