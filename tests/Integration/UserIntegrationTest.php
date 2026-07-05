<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

final class UserIntegrationTest extends IntegrationTestCase
{
    public function testGetOwnAccount(): void
    {
        $client = $this->requireClient();
        $user = $client->me()->get();
        self::assertNotNull($user);
        self::assertNotNull($user->getId());
        self::assertNotSame('', $user->getId());
    }

    public function testGetMonthlyUsage(): void
    {
        $client = $this->requireClient();
        self::assertNotEmpty($client->me()->monthlyUsage());
    }

    public function testGetMonthlyUsageForDate(): void
    {
        $client = $this->requireClient();
        self::assertNotEmpty($client->me()->monthlyUsage('2026-06-01'));
    }

    public function testGetLimits(): void
    {
        $client = $this->requireClient();
        self::assertNotEmpty($client->me()->limits());
    }
}
