<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\PaginationList;
use Apify\Client\Model\Task;
use Apify\Client\Options\ListOptions;
use Generator;

/** A client for the Actor task collection ({@code GET/POST /v2/actor-tasks}). */
final class TaskCollectionClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $baseUrl)
    {
        $this->ctx = ResourceContext::collection($http, $baseUrl, 'actor-tasks');
    }

    /**
     * Lists the account's tasks.
     *
     * @return PaginationList<Task>
     */
    public function list(?ListOptions $options = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new ListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new Task($d));
    }

    /**
     * Lazily iterates over the account's tasks, fetching pages on demand. The options' {@code limit}
     * caps the total number of tasks yielded across all pages ({@code null} = all); {@code $chunkSize}
     * is the per-page size ({@code null} = the server default).
     *
     * @return Generator<int,Task>
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
     * Creates a new task.
     *
     * @param mixed $task any JSON-serializable task definition
     */
    public function create(mixed $task): Task
    {
        return new Task($this->ctx->createResource(new QueryParams(), $task));
    }
}
