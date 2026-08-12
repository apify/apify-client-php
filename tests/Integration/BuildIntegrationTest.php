<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Options\ActorBuildOptions;
use Apify\Client\Options\ListOptions;

final class BuildIntegrationTest extends IntegrationTestCase
{
    public function testListBuilds(): void
    {
        $client = $this->requireClient();
        $page = $client->builds()->list(new ListOptions(limit: 5));
        self::assertLessThanOrEqual(5, count($page->getItems()));
        self::assertSame(count($page->getItems()), $page->getCount());
        self::assertGreaterThanOrEqual(count($page->getItems()), $page->getTotal());
    }

    public function testIterateBuilds(): void
    {
        $client = $this->requireClient();
        $created = $client->actors()->create(self::minimalActor(self::uniqueName('iter-build')));
        try {
            $actor = $client->actor((string) $created->getId());
            $build = $actor->build('0.0', new ActorBuildOptions());
            $client->build((string) $build->getId())->waitForFinish(300);
            // Iterate the Actor's builds (scoped, so the created build is the only expected entry).
            // Retried with backoff: build listing is eventually consistent under concurrent load.
            self::assertEventuallyIterated([(string) $build->getId()], static function () use ($actor): array {
                $seen = [];
                foreach ($actor->builds()->iterate(new ListOptions(), 1) as $b) {
                    $seen[(string) $b->getId()] = true;
                }
                return $seen;
            }, 'build');
        } finally {
            $client->actor((string) $created->getId())->delete();
        }
    }

    public function testBuildActorFlow(): void
    {
        $client = $this->requireClient();
        $created = $client->actors()->create(self::minimalActor(self::uniqueName('build')));
        try {
            $build = $client->actor((string) $created->getId())->build('0.0', new ActorBuildOptions());
            $finished = $client->build((string) $build->getId())->waitForFinish(300);
            self::assertTrue($finished->isTerminal(), 'build did not finish: ' . $finished->getStatus());

            self::assertNotNull($client->build((string) $build->getId())->get());
            $client->build((string) $build->getId())->log()->get();
            $client->build((string) $build->getId())->getOpenApiDefinition();
        } finally {
            $client->actor((string) $created->getId())->delete();
        }
    }
}
