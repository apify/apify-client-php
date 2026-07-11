<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\PaginationList;
use Apify\Client\Model\Schedule;
use Apify\Client\Options\ListOptions;
use Generator;

/** A client for the schedule collection ({@code GET/POST /v2/schedules}). */
final class ScheduleCollectionClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $baseUrl)
    {
        $this->ctx = ResourceContext::collection($http, $baseUrl, 'schedules');
    }

    /**
     * Lists the account's schedules.
     *
     * @return PaginationList<Schedule>
     */
    public function list(?ListOptions $options = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new ListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new Schedule($d));
    }

    /**
     * Lazily iterates over the account's schedules, fetching pages on demand. The options'
     * {@code limit} caps the total number of schedules yielded across all pages ({@code null} = all);
     * {@code $chunkSize} is the per-page size ({@code null} = the server default).
     *
     * @return Generator<int,Schedule>
     */
    public function iterate(?ListOptions $options = null, ?int $chunkSize = null): Generator
    {
        $options ??= new ListOptions();
        return ResourceContext::paginateOffset(
            $options->offset ?? 0,
            $options->limit,
            $chunkSize,
            fn (int $offset, ?int $pageLimit) => $this->list($options->withPagination($offset, $pageLimit)),
        );
    }

    /**
     * Creates a new schedule.
     *
     * @param mixed $schedule any JSON-serializable schedule definition
     */
    public function create(mixed $schedule): Schedule
    {
        return new Schedule($this->ctx->createResource(new QueryParams(), $schedule));
    }
}
