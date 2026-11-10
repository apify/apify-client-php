<?php

declare(strict_types=1);

namespace Apify\Client\Options;

/**
 * Options for {@see \Apify\Client\Resource\RunClient::iterateDatasetItems()}. Only the item filters that keep
 * row offsets stable are offered, since options such as {@code desc} or {@code view} would break the live
 * iteration.
 */
final class RunIterateDatasetItemsOptions
{
    /**
     * @param list<string>|null $fields fields to include (all others are left out)
     * @param list<string>|null $omit   fields to leave out
     * @param list<string>|null $unwind array fields whose elements become separate items
     */
    public function __construct(
        /** Number of dataset rows to skip from the beginning (default 0). */
        public readonly ?int $offset = null,
        /**
         * The maximum number of dataset rows to scan (default: no limit). Filters can yield fewer items, {@code unwind}
         * more.
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
}
