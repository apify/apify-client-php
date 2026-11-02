<?php

declare(strict_types=1);

namespace Apify\Client\Options;

/**
 * Options for {@see \Apify\Client\Resource\RunClient::iterateDatasetItems()}, which reads a run's
 * default dataset while the run is still producing items.
 *
 * Only the item filters that keep row offsets stable are offered; ordering and format options such as
 * {@code desc} or {@code view} would break the offset arithmetic the live iteration relies on.
 */
final class RunIterateDatasetItemsOptions
{
    /**
     * @param list<string>|null $fields field names to include in the items (all others are omitted)
     * @param list<string>|null $omit   field names to exclude from the items
     * @param list<string>|null $unwind field names to unwind: each array value becomes a separate item
     */
    public function __construct(
        /** Number of dataset rows to skip from the beginning (default 0). */
        public readonly ?int $offset = null,
        /**
         * Maximum number of dataset rows to scan (default: no limit). Fewer items are yielded when
         * filters drop some, more when {@code unwind} splits a row into several.
         */
        public readonly ?int $limit = null,
        /** Maximum number of dataset rows requested per API call (default 1000). */
        public readonly ?int $chunkSize = null,
        /** If {@code true}, yields only non-empty items and skips hidden fields. */
        public readonly ?bool $clean = null,
        public readonly ?array $fields = null,
        public readonly ?array $omit = null,
        /** If {@code true}, skips empty items. */
        public readonly ?bool $skipEmpty = null,
        /** If {@code true}, skips hidden fields (fields starting with {@code #}). */
        public readonly ?bool $skipHidden = null,
        public readonly ?array $unwind = null,
        /** How long to wait for the run to finish between polls, in seconds (default 5). */
        public readonly ?int $pollIntervalSecs = null,
    ) {
    }

    /** The item filters as dataset listing options for the given page, ready to send. @internal */
    public function toListItemsOptions(int $offset, int $limit): DatasetListItemsOptions
    {
        return new DatasetListItemsOptions(
            offset: $offset,
            limit: $limit,
            fields: $this->fields,
            omit: $this->omit,
            skipEmpty: $this->skipEmpty,
            skipHidden: $this->skipHidden,
            clean: $this->clean,
            unwind: $this->unwind,
        );
    }

    /** Whether any filter can drop whole rows or multiply them, so a page's item count is not its row count. @internal */
    public function hasRowChangingFilter(): bool
    {
        return $this->clean === true || $this->skipEmpty === true || ($this->unwind !== null && $this->unwind !== []);
    }
}
