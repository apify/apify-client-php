<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** A page of keys from a key-value store. */
final class KeyValueStoreKeysPage
{
    /**
     * @param list<KeyValueStoreKey> $items
     */
    public function __construct(
        private array $items,
        private int $limit,
        private bool $isTruncated,
        private ?string $exclusiveStartKey,
        private ?string $nextExclusiveStartKey,
    ) {
    }

    /**
     * @param mixed $data the decoded keys-page object
     */
    public static function fromData(mixed $data): self
    {
        $data = is_array($data) ? $data : [];
        $rawItems = (isset($data['items']) && is_array($data['items'])) ? array_values($data['items']) : [];
        $items = array_map(
            static fn ($item) => new KeyValueStoreKey(is_array($item) ? $item : []),
            $rawItems
        );

        return new self(
            $items,
            (int) ($data['limit'] ?? count($items)),
            (bool) ($data['isTruncated'] ?? false),
            isset($data['exclusiveStartKey']) ? (string) $data['exclusiveStartKey'] : null,
            isset($data['nextExclusiveStartKey']) ? (string) $data['nextExclusiveStartKey'] : null,
        );
    }

    /**
     * The listed keys.
     *
     * @return list<KeyValueStoreKey>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /** The maximum number of keys requested. */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /** Whether more keys are available. */
    public function isTruncated(): bool
    {
        return $this->isTruncated;
    }

    /** The key the listing started after. */
    public function getExclusiveStartKey(): ?string
    {
        return $this->exclusiveStartKey;
    }

    /** The key to pass to fetch the next page. */
    public function getNextExclusiveStartKey(): ?string
    {
        return $this->nextExclusiveStartKey;
    }
}
