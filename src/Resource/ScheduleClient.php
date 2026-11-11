<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\Schedule;

/** A client for a specific schedule ({@code /v2/schedules/{scheduleId}}). */
final class ScheduleClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $baseUrl, string $id)
    {
        $this->ctx = ResourceContext::single($http, $baseUrl, 'schedules', $id);
    }

    /** Fetches the schedule, or {@code null} if it does not exist. */
    public function get(int|float|string|null $timeoutSecs = null): ?Schedule
    {
        $data = $this->ctx->getResource('', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? new Schedule($data) : null;
    }

    /**
     * Updates the schedule and returns it.
     *
     * @param mixed $newFields any JSON-serializable fields
     */
    public function update(mixed $newFields, int|float|string|null $timeoutSecs = null): Schedule
    {
        return new Schedule($this->ctx->updateResource('', $newFields, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)));
    }

    /** Deletes the schedule. */
    public function delete(int|float|string|null $timeoutSecs = null): void
    {
        $this->ctx->deleteResource('', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
    }

    /**
     * Fetches the invocation log of the schedule: one entry per invocation, with {@code message}, {@code level}
     * and {@code createdAt}. A schedule that has not run has an empty log. A 404 is always thrown.
     *
     * @return list<array<string,mixed>>
     */
    public function getLog(int|float|string|null $timeoutSecs = null): array
    {
        $entries = $this->ctx->getResourceRequired('log', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM));
        /** @var list<array<string,mixed>> $log */
        $log = is_array($entries) ? array_values($entries) : [];
        return $log;
    }
}
