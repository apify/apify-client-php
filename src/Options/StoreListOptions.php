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
        /**
         * Maximum number of Actors to return. When iterating, this caps the total number of Actors
         * yielded across all pages (the per-page size is the separate {@code chunkSize} argument).
         */
        public readonly ?int $limit = null,
        /** Full-text search query. */
        public readonly ?string $search = null,
        /** The sort field (e.g. {@code "popularity"}, {@code "newest"}). */
        public readonly ?string $sortBy = null,
        /** Filter Actors by category. */
        public readonly ?string $category = null,
        /** Filter Actors by owner username. */
        public readonly ?string $username = null,
        /**
         * Filter Actors by pricing model ({@code FREE}, {@code FLAT_PRICE_PER_MONTH},
         * {@code PRICE_PER_DATASET_ITEM}, {@code PAY_PER_EVENT}).
         */
        public readonly ?string $pricingModel = null,
        /** Include Actors the current user cannot run. */
        public readonly ?bool $includeUnrunnableActors = null,
        /** Filter to Actors that allow agentic users. */
        public readonly ?bool $allowsAgenticUsers = null,
        /** The response format ({@code full}, {@code agent}). */
        public readonly ?string $responseFormat = null,
    ) {
    }

    /**
     * Returns a copy of these options with a new {@code offset}/{@code limit}, preserving the other
     * filters. Used by lazy iteration to request successive pages.
     */
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
            ->addString('responseFormat', $this->responseFormat);
    }
}
