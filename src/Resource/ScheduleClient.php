<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
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
    public function get(): ?Schedule
    {
        $data = $this->ctx->getResource('', new QueryParams());
        return is_array($data) ? new Schedule($data) : null;
    }

    /**
     * Updates the schedule with the given fields and returns the updated object.
     *
     * @param mixed $newFields any JSON-serializable set of fields to update
     */
    public function update(mixed $newFields): Schedule
    {
        return new Schedule($this->ctx->updateResource('', $newFields));
    }

    /** Deletes the schedule. */
    public function delete(): void
    {
        $this->ctx->deleteResource('');
    }

    /** Fetches the schedule's invocation log as text, or {@code null} if absent. */
    public function getLog(): ?string
    {
        $response = $this->ctx->getRaw('log', new QueryParams());
        return $response === null ? null : (string) $response->getBody();
    }
}
