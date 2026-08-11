<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/**
 * The result of releasing all of a client's request locks on a queue. Returned by
 * {@see \Apify\Client\Resource\RequestQueueClient::unlockRequests()}.
 */
final class UnlockRequestsResult
{
    public function __construct(private int $unlockedCount)
    {
    }

    /**
     * @param mixed $data the decoded response object
     */
    public static function fromData(mixed $data): self
    {
        $data = is_array($data) ? $data : [];
        return new self((int) ($data['unlockedCount'] ?? 0));
    }

    /** The number of requests that were unlocked. */
    public function getUnlockedCount(): int
    {
        return $this->unlockedCount;
    }
}
