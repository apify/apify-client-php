<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\Dataset;
use Apify\Client\Model\PaginationList;
use Apify\Client\Options\StorageListOptions;
use Generator;

/** A client for the dataset collection ({@code GET/POST /v2/datasets}). */
final class DatasetCollectionClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $baseUrl)
    {
        $this->ctx = ResourceContext::collection($http, $baseUrl, 'datasets');
    }

    /**
     * Lists datasets.
     *
     * @return PaginationList<Dataset>
     */
    public function list(?StorageListOptions $options = null, int|float|string|null $timeoutSecs = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new StorageListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new Dataset($d), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM));
    }

    /**
     * Lazily yields datasets. The {@code limit} option caps the total ({@code null} for all) and
     * {@code $chunkSize} sets the page size ({@code null} for the API's default).
     *
     * @return Generator<int,Dataset>
     */
    public function iterate(?StorageListOptions $options = null, ?int $chunkSize = null, int|float|string|null $timeoutSecs = null): Generator
    {
        $options ??= new StorageListOptions();
        return ResourceContext::paginateOffset(
            $options->offset ?? 0,
            $options->limit,
            $chunkSize,
            fn (int $offset, ?int $pageLimit) => $this->list($options->withPagination($offset, $pageLimit), $timeoutSecs),
        );
    }

    /**
     * Returns the dataset with the name, creating it if needed. An empty name creates an unnamed dataset. The
     * {@code $schema} is sent when the dataset is created.
     *
     * @param array<string,mixed>|null $schema
     */
    public function getOrCreate(?string $name = null, ?array $schema = null, int|float|string|null $timeoutSecs = null): Dataset
    {
        return new Dataset($this->ctx->getOrCreateNamed($name, $schema, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)));
    }
}
