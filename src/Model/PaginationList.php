<?php

declare(strict_types=1);

namespace Apify\Client\Model;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * A single page of an offset/limit-paginated list.
 *
 * The pagination metadata ({@see getTotal()}, {@see getOffset()}, {@see getLimit()},
 * {@see getCount()}, {@see isDesc()}) accompanies the {@see getItems()} items. Note: {@code total}
 * reflects the API's reported total, which can briefly lag immediately after a write (the count is
 * computed asynchronously) — re-read after a short delay if you need an exact post-write total.
 *
 * @template T
 * @implements IteratorAggregate<int,T>
 */
final class PaginationList implements IteratorAggregate, \Countable
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        private array $items,
        private int $total,
        private int $offset,
        private int $limit,
        private int $count,
        private bool $desc,
    ) {
    }

    /**
     * @template TItem
     * @param mixed $data the decoded paginated object
     * @param callable(array<string,mixed>):TItem $hydrate maps each raw item to a model
     * @return self<TItem>
     */
    public static function fromData(mixed $data, callable $hydrate): self
    {
        $data = is_array($data) ? $data : [];
        $rawItems = (isset($data['items']) && is_array($data['items'])) ? array_values($data['items']) : [];
        $items = array_map(
            static fn ($item) => $hydrate(is_array($item) ? $item : []),
            $rawItems
        );

        return new self(
            $items,
            (int) ($data['total'] ?? count($items)),
            (int) ($data['offset'] ?? 0),
            (int) ($data['limit'] ?? count($items)),
            (int) ($data['count'] ?? count($items)),
            (bool) ($data['desc'] ?? false),
        );
    }

    /**
     * Builds a page directly from items and metadata (used by the dataset-items endpoint, which
     * returns a bare array with pagination in response headers).
     *
     * @template TItem
     * @param list<TItem> $items
     * @return self<TItem>
     */
    public static function fromItems(array $items, int $total, int $offset, int $limit, int $count, bool $desc): self
    {
        return new self($items, $total, $offset, $limit, $count, $desc);
    }

    /**
     * The items of this page (never {@code null}).
     *
     * @return list<T>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /** Total number of items available across all pages. */
    public function getTotal(): int
    {
        return $this->total;
    }

    /** Number of items skipped at the start. */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /** Maximum number of items the API would return for this request. */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /** Number of items actually returned in this page. */
    public function getCount(): int
    {
        return $this->count;
    }

    /** Whether the items are in descending order. */
    public function isDesc(): bool
    {
        return $this->desc;
    }

    /**
     * @return Traversable<int,T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
