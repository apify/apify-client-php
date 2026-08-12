<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/**
 * A batch of requests from the head of a request queue, locked for exclusive processing. Returned by
 * {@see \Apify\Client\Resource\RequestQueueClient::listAndLockHead()}.
 */
final class LockedRequestQueueHead
{
    /**
     * @param list<RequestQueueRequest> $items
     */
    public function __construct(
        private array $items,
        private int $limit,
        private bool $hadMultipleClients,
        private int $lockSecs,
        private ?bool $queueHasLockedRequests,
        private ?string $clientKey,
        private ?string $queueModifiedAt = null,
    ) {
    }

    /**
     * @param mixed $data the decoded locked-head object
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
            (int) ($data['lockSecs'] ?? 0),
            isset($data['queueHasLockedRequests']) ? (bool) $data['queueHasLockedRequests'] : null,
            isset($data['clientKey']) ? (string) $data['clientKey'] : null,
            isset($data['queueModifiedAt']) ? (string) $data['queueModifiedAt'] : null,
        );
    }

    /**
     * The locked requests from the head of the queue. Each item's own
     * {@see RequestQueueRequest::getLockExpiresAt()} reports when its individual lock expires.
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

    /** The lock duration applied to every returned request, in seconds. */
    public function getLockSecs(): int
    {
        return $this->lockSecs;
    }

    /** Whether the queue has any requests locked by any client (this one or another). */
    public function queueHasLockedRequests(): ?bool
    {
        return $this->queueHasLockedRequests;
    }

    /** The client key used to acquire the locks. */
    public function getClientKey(): ?string
    {
        return $this->clientKey;
    }

    /** ISO 8601 timestamp of the last modification to the queue. */
    public function getQueueModifiedAt(): ?string
    {
        return $this->queueModifiedAt;
    }
}
