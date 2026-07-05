<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** Returned when adding or updating a request in a queue. */
final class RequestQueueOperationInfo extends ApifyResource
{
    /** The ID of the affected request. */
    public function getRequestId(): ?string
    {
        return $this->getString('requestId');
    }

    /**
     * The unique key of the affected request. Populated for batch-add results; may be {@code null}
     * for single add/update operations.
     */
    public function getUniqueKey(): ?string
    {
        return $this->getString('uniqueKey');
    }

    /** Whether the request was already in the queue. */
    public function wasAlreadyPresent(): ?bool
    {
        return $this->getBool('wasAlreadyPresent');
    }

    /** Whether the request had already been handled. */
    public function wasAlreadyHandled(): ?bool
    {
        return $this->getBool('wasAlreadyHandled');
    }
}
