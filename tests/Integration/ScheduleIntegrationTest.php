<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Options\ListOptions;

final class ScheduleIntegrationTest extends IntegrationTestCase
{
    /**
     * @return array<string,mixed>
     */
    private static function scheduleDef(string $name): array
    {
        return [
            'name' => $name,
            'cronExpression' => '0 0 * * *',
            'isEnabled' => false,
            'isExclusive' => true,
            'actions' => [],
        ];
    }

    public function testListSchedules(): void
    {
        $client = $this->requireClient();
        $page = $client->schedules()->list(new ListOptions(limit: 5));
        self::assertLessThanOrEqual(5, count($page->getItems()));
        self::assertSame(count($page->getItems()), $page->getCount());
        self::assertGreaterThanOrEqual(count($page->getItems()), $page->getTotal());
    }

    public function testGetSchedule(): void
    {
        $client = $this->requireClient();
        $sch = $client->schedules()->create(self::scheduleDef(self::uniqueName('sch-get')));
        try {
            $got = $client->schedule((string) $sch->getId())->get();
            self::assertNotNull($got);
            self::assertSame($sch->getId(), $got->getId());
        } finally {
            $client->schedule((string) $sch->getId())->delete();
        }
    }

    public function testIterateSchedules(): void
    {
        $client = $this->requireClient();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = (string) $client->schedules()->create(self::scheduleDef(self::uniqueName('iter-sch')))->getId();
        }
        try {
            $seen = [];
            foreach ($client->schedules()->iterate(new ListOptions(desc: true), 2) as $schedule) {
                $seen[(string) $schedule->getId()] = true;
            }
            foreach ($ids as $id) {
                self::assertArrayHasKey($id, $seen, "iterate() did not yield created schedule $id");
            }
        } finally {
            foreach ($ids as $id) {
                $client->schedule($id)->delete();
            }
        }
    }

    public function testScheduleCrudFlow(): void
    {
        $client = $this->requireClient();
        $sch = $client->schedules()->create(self::scheduleDef(self::uniqueName('sch-crud')));
        try {
            $schedule = $client->schedule((string) $sch->getId());
            self::assertNotNull($schedule->get());
            $updated = $schedule->update(['cronExpression' => '0 12 * * *']);
            self::assertSame('0 12 * * *', $updated->getCronExpression());
            // A fresh schedule may have no log yet (null), which is a valid result — we only assert
            // the call itself succeeds.
            $schedule->getLog();
        } finally {
            $client->schedule((string) $sch->getId())->delete();
        }
    }
}
