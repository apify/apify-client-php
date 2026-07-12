<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Options\ListOptions;
use Apify\Client\Options\RunListOptions;

final class TaskIntegrationTest extends IntegrationTestCase
{
    /**
     * @return array<string,mixed>
     */
    private static function taskDef(string $name): array
    {
        return [
            'actId' => 'apify/hello-world',
            'name' => $name,
            'options' => ['build' => 'latest', 'memoryMbytes' => 256, 'timeoutSecs' => 60],
            'input' => ['message' => 'hello'],
        ];
    }

    public function testListTasks(): void
    {
        $client = $this->requireClient();
        $page = $client->tasks()->list(new ListOptions(limit: 5));
        self::assertLessThanOrEqual(5, count($page->getItems()));
        self::assertSame(count($page->getItems()), $page->getCount());
        self::assertGreaterThanOrEqual(count($page->getItems()), $page->getTotal());
    }

    public function testGetTask(): void
    {
        $client = $this->requireClient();
        $task = $client->tasks()->create(self::taskDef(self::uniqueName('task-get')));
        try {
            $got = $client->task((string) $task->getId())->get();
            self::assertNotNull($got);
            self::assertSame($task->getId(), $got->getId());
        } finally {
            $client->task((string) $task->getId())->delete();
        }
    }

    public function testIterateTasks(): void
    {
        $client = $this->requireClient();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = (string) $client->tasks()->create(self::taskDef(self::uniqueName('iter-task')))->getId();
        }
        try {
            $seen = [];
            foreach ($client->tasks()->iterate(new ListOptions(desc: true), 2) as $task) {
                $seen[(string) $task->getId()] = true;
            }
            foreach ($ids as $id) {
                self::assertArrayHasKey($id, $seen, "iterate() did not yield created task $id");
            }
        } finally {
            foreach ($ids as $id) {
                $client->task($id)->delete();
            }
        }
    }

    public function testTaskCrudFlow(): void
    {
        $client = $this->requireClient();
        $task = $client->tasks()->create(self::taskDef(self::uniqueName('task-crud')));
        try {
            $tc = $client->task((string) $task->getId());
            self::assertNotNull($tc->get());
            $tc->updateInput(['message' => 'updated']);
            self::assertNotNull($tc->getInput());
            $tc->update(['name' => self::uniqueName('task-renamed')]);
            $tc->runs()->list(new ListOptions(), new RunListOptions());
        } finally {
            $client->task((string) $task->getId())->delete();
        }
    }
}
