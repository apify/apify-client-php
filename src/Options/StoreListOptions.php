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
        /** Maximum number of Actors to return (also the per-page size when iterating). */
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

    /** Returns a copy of these options with a new {@code offset} (used by lazy iteration). */
    public function withOffset(?int $offset): self
    {
        return new self(
            $offset,
            $this->limit,
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
