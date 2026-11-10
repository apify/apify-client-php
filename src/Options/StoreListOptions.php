<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/** Options for listing/iterating the Apify Store ({@code GET /v2/store}). */
final class StoreListOptions
{
    public function __construct(
        /** Number of Actors to skip. */
        public readonly ?int $offset = null,
        /** The maximum number of Actors. When iterating, it caps the total over all pages, not the page size ({@code chunkSize}). */
        public readonly ?int $limit = null,
        /** Full-text search query. */
        public readonly ?string $search = null,
        /** The sort field (e.g. {@code "popularity"}, {@code "newest"}). */
        public readonly ?string $sortBy = null,
        /** Filter Actors by category. */
        public readonly ?string $category = null,
        /** Filter Actors by owner username. */
        public readonly ?string $username = null,
        /** Filters by pricing model ({@code FREE}, {@code PRICE_PER_DATASET_ITEM}, {@code PAY_PER_EVENT}). */
        public readonly ?string $pricingModel = null,
        /** Include Actors the current user cannot run. */
        public readonly ?bool $includeUnrunnableActors = null,
        /** Filter to Actors that allow agentic users. */
        public readonly ?bool $allowsAgenticUsers = null,
        /** The response format ({@code full}, {@code agent}). */
        public readonly ?string $responseFormat = null,
        /** Includes the compact input schema of the default build, when it succeeded and defines input properties. */
        public readonly ?bool $includeInputSchema = null,
    ) {
    }

    /** A copy with a new {@code offset} and {@code limit} and the same filters. Used to request successive pages. */
    public function withPagination(?int $offset, ?int $limit): self
    {
        return new self(
            $offset,
            $limit,
            $this->search,
            $this->sortBy,
            $this->category,
            $this->username,
            $this->pricingModel,
            $this->includeUnrunnableActors,
            $this->allowsAgenticUsers,
            $this->responseFormat,
            $this->includeInputSchema,
        );
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addInt('offset', $this->offset)
            ->addInt('limit', $this->limit)
            ->addString('search', $this->search)
            ->addString('sortBy', $this->sortBy)
            ->addString('category', $this->category)
            ->addString('username', $this->username)
            ->addString('pricingModel', $this->pricingModel)
            ->addBool('includeUnrunnableActors', $this->includeUnrunnableActors)
            ->addBool('allowsAgenticUsers', $this->allowsAgenticUsers)
            ->addString('responseFormat', $this->responseFormat)
            ->addBool('includeInputSchema', $this->includeInputSchema);
    }
}
