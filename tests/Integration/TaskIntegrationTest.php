<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Exception\ApifyApiException;
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
            // Retried with backoff: task listing is eventually consistent under concurrent load.
            self::assertEventuallyIterated($ids, static function () use ($client): array {
                $seen = [];
                foreach ($client->tasks()->iterate(new ListOptions(desc: true), 2) as $task) {
                    $seen[(string) $task->getId()] = true;
                }
                return $seen;
            }, 'task');
        } finally {
            foreach ($ids as $id) {
                $client->task($id)->delete();
            }
        }
    }

    public function testPublishUnpublish(): void
    {
        $client = $this->requireClient();
        $task = $client->tasks()->create(self::taskDef(self::uniqueName('task-publish')));
        try {
            $tc = $client->task((string) $task->getId());

            // unpublish() is safe to call even on an unpublished task (no publicConfig required).
            // isPublic() may come back null rather than false if the API omits the field entirely
            // for a task that never had publicConfig set up, so only assert it isn't true.
            $unpublished = $tc->unpublish();
            self::assertNotSame(true, $unpublished->isPublic());

            // publish() validates the task's Actor (must be public, write-permitted) and its
            // publicConfig (must be set up). This task has neither: its Actor (apify/hello-world)
            // is not owned by the test account, and it has no publicConfig, so this is expected to
            // fail (403 for the permission check, or 400 if the API rejects the missing
            // publicConfig first) rather than silently succeed.
            try {
                $tc->publish();
                self::fail('expected publish() to fail: the task has no publicConfig and its Actor is not owned by the test account');
            } catch (ApifyApiException $e) {
                self::assertContains($e->getStatusCode(), [400, 403]);
            }
        } finally {
            $client->task((string) $task->getId())->delete();
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
            $updated = $tc->update([
                'name' => self::uniqueName('task-renamed'),
                'title' => 'Updated Title',
                'description' => 'Updated description',
            ]);
            self::assertSame('Updated Title', $updated->getTitle());
            self::assertSame('Updated description', $updated->getDescription());
            $tc->runs()->list(new ListOptions(), new RunListOptions());
        } finally {
            $client->task((string) $task->getId())->delete();
        }
    }
}
