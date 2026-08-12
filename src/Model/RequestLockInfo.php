<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/**
 * The result of prolonging a request lock. Returned by
 * {@see \Apify\Client\Resource\RequestQueueClient::prolongRequestLock()}.
 */
final class RequestLockInfo
{
    public function __construct(private ?string $lockExpiresAt)
    {
    }

    /**
     * @param mixed $data the decoded response object
     */
    public static function fromData(mixed $data): self
    {
        $data = is_array($data) ? $data : [];
        return new self(isset($data['lockExpiresAt']) ? (string) $data['lockExpiresAt'] : null);
    }

    /** ISO 8601 timestamp of when the (possibly just-extended) lock expires. */
    public function getLockExpiresAt(): ?string
    {
        return $this->lockExpiresAt;
    }
}
