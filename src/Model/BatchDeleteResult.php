<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/**
 * The result of a batch request-delete: the requests that were successfully removed and the ones
 * that could not be (and can be retried). Returned by
 * {@see \Apify\Client\Resource\RequestQueueClient::batchDeleteRequests()}.
 */
final class BatchDeleteResult
{
    /**
     * @param list<RequestQueueRequest> $processedRequests
     * @param list<RequestQueueRequest> $unprocessedRequests
     */
    public function __construct(
        private array $processedRequests = [],
        private array $unprocessedRequests = [],
    ) {
    }

    /**
     * @param mixed $data the decoded response object
     */
    public static function fromData(mixed $data): self
    {
        $data = is_array($data) ? $data : [];
        return new self(
            self::hydrateList($data['processedRequests'] ?? null),
            self::hydrateList($data['unprocessedRequests'] ?? null),
        );
    }

    /**
     * @return list<RequestQueueRequest>
     */
    private static function hydrateList(mixed $rawList): array
    {
        $rawList = is_array($rawList) ? array_values($rawList) : [];
        return array_map(
            static fn ($item) => RequestQueueRequest::fromArray(is_array($item) ? $item : []),
            $rawList
        );
    }

    /**
     * The requests that were successfully deleted from the queue.
     *
     * @return list<RequestQueueRequest>
     */
    public function getProcessedRequests(): array
    {
        return $this->processedRequests;
    }

    /**
     * The requests that failed to be deleted and can be retried.
     *
     * @return list<RequestQueueRequest>
     */
    public function getUnprocessedRequests(): array
    {
        return $this->unprocessedRequests;
    }
}
