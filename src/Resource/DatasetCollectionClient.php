<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\Dataset;
use Apify\Client\Model\PaginationList;
use Apify\Client\Options\StorageListOptions;

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
    public function list(?StorageListOptions $options = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new StorageListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new Dataset($d));
    }

    /**
     * Gets the dataset with the given name, creating it if it does not exist. An empty/{@code null}
     * name creates a new unnamed dataset. An optional {@code $schema} (an associative array) is sent
     * when creating the dataset, mirroring the reference client's {@code getOrCreate(name, { schema })}.
     *
     * @param array<string,mixed>|null $schema
     */
    public function getOrCreate(?string $name = null, ?array $schema = null): Dataset
    {
        return new Dataset($this->ctx->getOrCreateNamed($name, $schema));
    }
}
