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
     * Updates the schedule with the given fields and returns the updated object.
     *
     * @param mixed $newFields any JSON-serializable set of fields to update
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
     * Fetches the schedule's invocation log as text.
     *
     * Unlike {@see get()}, a 404 here is not swallowed: it is always rethrown, since the only way this
     * fixed sub-path 404s is the schedule itself being gone (matching the reference client).
     */
    public function getLog(): ?string
    {
        return (string) $this->ctx->getRawRequired('log', new QueryParams())->getBody();
    }
}
