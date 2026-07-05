<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/**
 * The result of a batch request-add: the accepted (processed) and the unprocessed requests.
 */
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
     * The requests the API successfully added.
     *
     * @return list<RequestQueueOperationInfo>
     */
    public function getProcessedRequests(): array
    {
        return $this->processedRequests;
    }

    /**
     * The requests the API did not process.
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
     * Appends another result's requests into this one (used to merge per-chunk batch results).
     *
     * @internal
     */
    public function merge(BatchAddResult $other): void
    {
        $this->processedRequests = array_merge($this->processedRequests, $other->processedRequests);
        $this->unprocessedRequests = array_merge($this->unprocessedRequests, $other->unprocessedRequests);
    }
}
