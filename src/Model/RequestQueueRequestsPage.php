<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/**
 * A single, cursor-paginated page of a request queue's requests. Returned by
 * {@see \Apify\Client\Resource\RequestQueueClient::listRequests()}.
 */
final class RequestQueueRequestsPage
{
    /**
     * @param list<RequestQueueRequest> $items
     */
    public function __construct(
        private array $items,
        private int $limit,
        private ?string $exclusiveStartId,
        private ?string $cursor,
        private ?string $nextCursor,
    ) {
    }

    /**
     * @param mixed $data the decoded response object
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
            isset($data['exclusiveStartId']) ? (string) $data['exclusiveStartId'] : null,
            isset($data['cursor']) ? (string) $data['cursor'] : null,
            isset($data['nextCursor']) ? (string) $data['nextCursor'] : null,
        );
    }

    /**
     * The requests in this page.
     *
     * @return list<RequestQueueRequest>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /** The maximum number of requests requested for this page. */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * The ID of the last request of the previous page, if pagination was continued by ID.
     *
     * @deprecated superseded by {@see getCursor()}/{@see getNextCursor()}
     */
    public function getExclusiveStartId(): ?string
    {
        return $this->exclusiveStartId;
    }

    /** The cursor that produced this page, if pagination was continued by cursor. */
    public function getCursor(): ?string
    {
        return $this->cursor;
    }

    /** The cursor to pass to fetch the next page, or {@code null} if this is the last page. */
    public function getNextCursor(): ?string
    {
        return $this->nextCursor;
    }
}
