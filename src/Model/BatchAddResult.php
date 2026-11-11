<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** The result of adding requests in a batch: the processed and the unprocessed ones. */
final class BatchAddResult
{
    /**
     * @param list<RequestQueueOperationInfo> $processedRequests
     * @param list<RequestQueueRequest> $unprocessedRequests
     */
    public function __construct(
        private array $processedRequests = [],
        private array $unprocessedRequests = [],
    ) {
    }

    /**
     * The requests that were added.
     *
     * @return list<RequestQueueOperationInfo>
     */
    public function getProcessedRequests(): array
    {
        return $this->processedRequests;
    }

    /**
     * The requests that were not processed.
     *
     * @return list<RequestQueueRequest>
     */
    public function getUnprocessedRequests(): array
    {
        return $this->unprocessedRequests;
    }

    /**
     * @param list<RequestQueueOperationInfo> $processedRequests
     * @internal
     */
    public function setProcessedRequests(array $processedRequests): void
    {
        $this->processedRequests = $processedRequests;
    }

    /**
     * @param list<RequestQueueRequest> $unprocessedRequests
     * @internal
     */
    public function setUnprocessedRequests(array $unprocessedRequests): void
    {
        $this->unprocessedRequests = $unprocessedRequests;
    }

    /**
     * Adds the requests of another result to this one.
     *
     * @internal
     */
    public function merge(BatchAddResult $other): void
    {
        $this->processedRequests = array_merge($this->processedRequests, $other->processedRequests);
        $this->unprocessedRequests = array_merge($this->unprocessedRequests, $other->unprocessedRequests);
    }
}
