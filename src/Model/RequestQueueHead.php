<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** The head (front) of a request queue. */
final class RequestQueueHead
{
    /**
     * @param list<RequestQueueRequest> $items
     */
    public function __construct(
        private array $items,
        private int $limit,
        private bool $hadMultipleClients,
    ) {
    }

    /**
     * @param mixed $data the decoded queue-head object
     */
    public static function fromData(mixed $data): self
    {
        $data = is_array($data) ? $data : [];
        $rawItems = (isset($data['items']) && is_array($data['items'])) ? array_values($data['items']) : [];
        $items = array_map(
            static fn ($item) => RequestQueueRequest::fromArray(is_array($item) ? $item : []),
            $rawItems
        );

        return new self(
            $items,
            (int) ($data['limit'] ?? count($items)),
            (bool) ($data['hadMultipleClients'] ?? false),
        );
    }

    /**
     * The requests at the head of the queue.
     *
     * @return list<RequestQueueRequest>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /** The maximum number of requests requested. */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /** Whether multiple clients have accessed the queue. */
    public function hadMultipleClients(): bool
    {
        return $this->hadMultipleClients;
    }
}
