<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Options\ListOptions;

final class WebhookIntegrationTest extends IntegrationTestCase
{
    /**
     * @return array<string,mixed>
     */
    private static function webhookDef(string $url): array
    {
        return [
            'isAdHoc' => true,
            'eventTypes' => ['ACTOR.RUN.SUCCEEDED'],
            'condition' => ['actorRunId' => 'ZZZZZZZZZZZZZZZZZ'],
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
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = (string) $client->webhooks()->create(self::webhookDef('https://example.com/iter-' . $i))->getId();
        }
        try {
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
    }

    public function testIterateWebhookDispatches(): void
    {
        $client = $this->requireClient();
        $wh = $client->webhooks()->create(self::webhookDef('https://example.com/dispatch-iter'));
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
    }

    public function testGetWebhook(): void
    {
        $client = $this->requireClient();
        $wh = $client->webhooks()->create(self::webhookDef('https://example.com/webhook'));
        try {
            $got = $client->webhook((string) $wh->getId())->get();
            self::assertNotNull($got);
            self::assertSame($wh->getId(), $got->getId());
        } finally {
            $client->webhook((string) $wh->getId())->delete();
        }
    }

    public function testGetWebhookDispatch(): void
    {
        $client = $this->requireClient();
        $wh = $client->webhooks()->create(self::webhookDef('https://example.com/webhook'));
        try {
            $dispatch = $client->webhook((string) $wh->getId())->test();
            $got = $client->webhookDispatch((string) $dispatch->getId())->get();
            self::assertNotNull($got);
            self::assertSame($dispatch->getId(), $got->getId());
        } finally {
            $client->webhook((string) $wh->getId())->delete();
        }
    }

    public function testWebhookCrudFlow(): void
    {
        $client = $this->requireClient();
        $wh = $client->webhooks()->create(self::webhookDef('https://example.com/webhook'));
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
    }
}
