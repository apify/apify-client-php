<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\BatchAddResult;
use Apify\Client\Model\BatchDeleteResult;
use Apify\Client\Model\LockedRequestQueueHead;
use Apify\Client\Model\RequestLockInfo;
use Apify\Client\Model\RequestQueue;
use Apify\Client\Model\RequestQueueHead;
use Apify\Client\Model\RequestQueueOperationInfo;
use Apify\Client\Model\RequestQueueRequest;
use Apify\Client\Model\RequestQueueRequestsPage;
use Apify\Client\Model\UnlockRequestsResult;
use Apify\Client\Options\BatchAddRequestsOptions;
use Apify\Client\Options\ListRequestsOptions;
use Apify\Client\Options\PaginateRequestsOptions;
use Apify\Client\Options\RequestQueueClientOptions;
use Generator;
use InvalidArgumentException;

/** A client for a specific request queue (and run-nested variants). */
final class RequestQueueClient
{
    /** The API limit on requests per batch call; larger inputs are split into chunks of this size. */
    private const MAX_REQUESTS_PER_BATCH = 25;

    /**
     * The API's maximum accepted request payload size (9 MiB). Batches are additionally split so no
     * single batch call exceeds this, matching the reference client's {@code sliceArrayByByteLength}.
     */
    private const MAX_PAYLOAD_SIZE_BYTES = 9 * 1024 * 1024;

    /** Safety margin (0.01%) subtracted from the payload limit, matching the reference client. */
    private const PAYLOAD_SAFETY_BUFFER_PERCENT = 0.0001;

    private function __construct(
        private HttpClientCore $http,
        private ResourceContext $ctx,
        private ?string $clientKey,
        private ?float $timeoutSecs = null,
    ) {
    }

    /** @internal */
    public static function forId(
        HttpClientCore $http,
        string $baseUrl,
        string $id,
        ?RequestQueueClientOptions $options = null
    ): self {
        $ctx = ResourceContext::single($http, $baseUrl, 'request-queues', $id);
        $timeoutSecs = $options?->timeoutSecs;
        $ctx->withTimeout($timeoutSecs);
        return new self($http, $ctx, $options?->clientKey, $timeoutSecs);
    }

    /**
     * Creates a client for a run's default request queue (nested path only, no ID). Any
     * {@code $inheritedParams} (e.g. the {@code status}/{@code origin} filters pinned by a last-run
     * accessor) become base params so every request resolves the correct run's queue. @internal
     */
    public static function nested(HttpClientCore $http, string $base, string $subPath, ?QueryParams $inheritedParams = null): self
    {
        $ctx = ResourceContext::collection($http, $base, $subPath);
        if ($inheritedParams !== null) {
            $ctx->baseParams = $inheritedParams->copy();
        }
        return new self($http, $ctx, null);
    }

    /**
     * Returns a copy of the client that identifies its requests with {@code $clientKey}. A stable
     * client key is required to operate on locks the client itself created, and lets the API detect
     * whether multiple clients access a queue.
     */
    public function withClientKey(string $clientKey): self
    {
        return new self($this->http, $this->ctx, $clientKey, $this->timeoutSecs);
    }

    private function applyClientKey(QueryParams $params): QueryParams
    {
        if ($this->clientKey !== null && $this->clientKey !== '') {
            $params->addString('clientKey', $this->clientKey);
        }
        return $params;
    }

    /** Fetches the queue metadata, or {@code null} if it does not exist. */
    public function get(): ?RequestQueue
    {
        $data = $this->ctx->getResource('', new QueryParams());
        return is_array($data) ? new RequestQueue($data) : null;
    }

    /**
     * Updates the queue metadata (e.g. name) and returns the updated object.
     *
     * @param mixed $newFields any JSON-serializable set of fields to update
     */
    public function update(mixed $newFields): RequestQueue
    {
        return new RequestQueue($this->ctx->updateResource('', $newFields));
    }

    /** Deletes the queue. */
    public function delete(): void
    {
        $this->ctx->deleteResource('');
    }

    /**
     * Returns the requests at the head (front) of the queue, up to {@code $limit} ({@code null} for
     * the server default).
     */
    public function listHead(?int $limit = null): RequestQueueHead
    {
        $params = new QueryParams();
        $params->addInt('limit', $limit);
        $this->applyClientKey($params);
        return RequestQueueHead::fromData($this->ctx->getResourceRequired('head', $params));
    }

    /** Adds a request to the queue. If {@code $forefront} is true, it is added to the front. */
    public function addRequest(RequestQueueRequest $request, bool $forefront = false): RequestQueueOperationInfo
    {
        $params = new QueryParams();
        $params->addBool('forefront', $forefront);
        $this->applyClientKey($params);
        $data = $this->ctx->postWithBody('requests', $params, Json::encode($request->toArray()), ResourceContext::CONTENT_TYPE_JSON);
        return new RequestQueueOperationInfo($data);
    }

    /** Fetches a request by ID, or {@code null} if it does not exist. */
    public function getRequest(string $id): ?RequestQueueRequest
    {
        $data = $this->ctx->getResource('requests/' . ResourceContext::encodePathSegment($id), new QueryParams());
        return is_array($data) ? RequestQueueRequest::fromArray($data) : null;
    }

    /**
     * Updates an existing request (identified by its ID field) and returns the operation info. If
     * {@code $forefront} is true, the request is moved to the front of the queue.
     */
    public function updateRequest(RequestQueueRequest $request, bool $forefront = false): RequestQueueOperationInfo
    {
        $params = new QueryParams();
        $params->addBool('forefront', $forefront);
        $this->applyClientKey($params);
        $url = $this->ctx->mergedParams($params)
            ->applyToUrl($this->ctx->subUrl('requests/' . ResourceContext::encodePathSegment((string) $request->getId())));
        $response = $this->http->call('PUT', $url, Json::encode($request->toArray()), ResourceContext::CONTENT_TYPE_JSON, timeoutSecs: $this->timeoutSecs);
        $data = Json::decodeData((string) $response->getBody());
        return new RequestQueueOperationInfo(is_array($data) ? $data : []);
    }

    /** Deletes a request by ID. */
    public function deleteRequest(string $id): void
    {
        $params = $this->applyClientKey(new QueryParams());
        $url = $this->ctx->mergedParams($params)
            ->applyToUrl($this->ctx->subUrl('requests/' . ResourceContext::encodePathSegment($id)));
        try {
            $this->http->call('DELETE', $url, timeoutSecs: $this->timeoutSecs);
        } catch (ApifyApiException $e) {
            if (!HttpClientCore::isNotFound($e)) {
                throw $e;
            }
        }
    }

    /**
     * Atomically returns and locks up to {@code $limit} requests from the head of the queue for
     * {@code $lockSecs} seconds.
     */
    public function listAndLockHead(int $lockSecs, ?int $limit = null): LockedRequestQueueHead
    {
        $params = new QueryParams();
        $params->addInt('lockSecs', $lockSecs)->addInt('limit', $limit);
        $this->applyClientKey($params);
        return LockedRequestQueueHead::fromData($this->ctx->postWithBody('head/lock', $params, null, ''));
    }

    /**
     * Adds multiple requests to the queue. If {@code $forefront} is true, they are added to the front.
     *
     * The input is automatically split into chunks of at most 25 requests (the API count limit) that
     * additionally respect the API's ~9 MiB payload-size limit (large {@code userData} batches are
     * split so they do not 413). Requests the API returns as unprocessed in a successful response
     * (typically rate-limited) are retried with exponential backoff; the per-chunk results are merged.
     *
     * Every request must carry a non-empty {@code uniqueKey}: the retry loop correlates the server's
     * per-request results by uniqueKey (and the API deduplicates by it), so a request without one
     * cannot be tracked. Consistent with the reference client, this method does not throw on API
     * errors: if a batch call fails and the transport did not retry, that chunk's not-yet-processed
     * requests are returned in {@see BatchAddResult::getUnprocessedRequests()} (earlier chunks'
     * results are still returned). Invalid input (empty uniqueKey, oversized request) is rejected up
     * front with {@see InvalidArgumentException} before any call.
     *
     * @param list<RequestQueueRequest> $requests
     * @throws InvalidArgumentException if any request has an empty uniqueKey or is individually too large
     */
    public function batchAddRequests(
        array $requests,
        bool $forefront = false,
        ?BatchAddRequestsOptions $options = null
    ): BatchAddResult {
        $options ??= new BatchAddRequestsOptions();
        $requests = array_values($requests);

        $payloadSizeLimitBytes = self::MAX_PAYLOAD_SIZE_BYTES
            - (int) ceil(self::MAX_PAYLOAD_SIZE_BYTES * self::PAYLOAD_SAFETY_BUFFER_PERCENT);

        // Validate the whole input up front, before any HTTP call. Both the empty-uniqueKey check and
        // the per-request oversized check must run here (not inside the send loop): otherwise an
        // oversized request in the middle of a large batch would only be discovered after earlier
        // chunks had already been POSTed, leaving the queue partially mutated.
        foreach ($requests as $i => $request) {
            $uniqueKey = $request->getUniqueKey();
            if ($uniqueKey === null || $uniqueKey === '') {
                throw new InvalidArgumentException(
                    sprintf('batchAddRequests: the request at index %d is missing a non-empty uniqueKey', $i)
                );
            }
            $itemBytes = strlen(Json::encode($request->toArray()));
            if ($itemBytes > $payloadSizeLimitBytes) {
                throw new InvalidArgumentException(sprintf(
                    'batchAddRequests: the request at index %d exceeds the maximum payload size (%d bytes)',
                    $i,
                    $payloadSizeLimitBytes
                ));
            }
        }

        $merged = new BatchAddResult();
        $index = 0;
        $count = count($requests);
        while ($index < $count) {
            // Bound each batch first by the count limit (25), then by payload byte size.
            $countSlice = array_slice($requests, $index, self::MAX_REQUESTS_PER_BATCH);
            $chunk = self::sliceByByteLength($countSlice, $payloadSizeLimitBytes);
            $merged->merge($this->batchAddChunkWithRetries($chunk, $forefront, $options));
            $index += count($chunk);
        }
        return $merged;
    }

    /**
     * Returns the longest leading run of {@code $requests} whose combined JSON payload stays under
     * {@code $maxByteLength}, always keeping at least one request so iteration makes progress. Ports
     * the reference client's {@code sliceArrayByByteLength}.
     *
     * Callers must have already validated (in {@see batchAddRequests()}) that every individual request
     * fits under {@code $maxByteLength}, so the always-keep-one fallback never produces an over-limit
     * chunk. That up-front validation is what lets this slicer run inside the send loop without risking
     * a partially-mutated queue.
     *
     * @param list<RequestQueueRequest> $requests
     * @return list<RequestQueueRequest>
     */
    private static function sliceByByteLength(array $requests, int $maxByteLength): array
    {
        $payloads = array_map(static fn (RequestQueueRequest $r) => $r->toArray(), $requests);
        if (strlen(Json::encode($payloads)) < $maxByteLength) {
            return $requests;
        }

        $sliced = [];
        $byteLength = 2; // the two bytes of an empty array "[]"
        foreach ($requests as $request) {
            $itemBytes = strlen(Json::encode($request->toArray()));
            if ($byteLength + $itemBytes >= $maxByteLength) {
                break;
            }
            $byteLength += $itemBytes;
            $sliced[] = $request;
        }

        // Guarantee forward progress: keep at least the first request (pre-validated to fit under the max).
        if ($sliced === []) {
            $sliced[] = $requests[0];
        }
        return $sliced;
    }

    /**
     * @param list<RequestQueueRequest> $chunk
     */
    private function batchAddChunkWithRetries(array $chunk, bool $forefront, BatchAddRequestsOptions $options): BatchAddResult
    {
        $maxRetries = $options->maxUnprocessedRequestsRetries;
        $minDelayMillis = $options->minDelayBetweenUnprocessedRequestsRetriesMillis;

        $remaining = $chunk;
        /** @var list<RequestQueueOperationInfo> $processed */
        $processed = [];
        /** @var list<RequestQueueRequest> $unprocessed */
        $unprocessed = [];

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->batchAddChunk($remaining, $forefront);
            } catch (ApifyApiException) {
                // Matches the JS reference (which mandates consistent error handling): when the HTTP
                // call fails and the transport did not (or was told not to) retry, the requests not yet
                // processed in THIS chunk are reported as unprocessed and we stop — keeping the method's
                // non-throwing contract so a multi-chunk call still returns every earlier chunk's
                // already-merged results instead of aborting the whole operation.
                $unprocessed = self::requestsNotYetProcessed($chunk, $processed);
                break;
            }
            $processed = array_merge($processed, $response->getProcessedRequests());
            // Only requests the API reports as unprocessed in this SUCCESSFUL response are retried.
            $unprocessed = $response->getUnprocessedRequests();
            $remaining = self::requestsNotYetProcessed($chunk, $processed);
            if ($remaining === []) {
                break;
            }
            if ($attempt < $maxRetries) {
                self::sleepBackoff($attempt, $minDelayMillis);
            }
        }

        $result = new BatchAddResult();
        $result->setProcessedRequests($processed);
        $result->setUnprocessedRequests($unprocessed);
        return $result;
    }

    /**
     * @param list<RequestQueueRequest> $requests
     */
    private function batchAddChunk(array $requests, bool $forefront): BatchAddResult
    {
        $params = new QueryParams();
        $params->addBool('forefront', $forefront);
        $this->applyClientKey($params);
        $payload = array_map(static fn (RequestQueueRequest $r) => $r->toArray(), $requests);
        $data = $this->ctx->postWithBody('requests/batch', $params, Json::encode($payload), ResourceContext::CONTENT_TYPE_JSON);

        $rawProcessed = (isset($data['processedRequests']) && is_array($data['processedRequests'])) ? $data['processedRequests'] : [];
        $processed = array_map(
            static fn ($p) => new RequestQueueOperationInfo(is_array($p) ? $p : []),
            array_values($rawProcessed)
        );
        $rawUnprocessed = (isset($data['unprocessedRequests']) && is_array($data['unprocessedRequests'])) ? $data['unprocessedRequests'] : [];
        $unprocessed = array_map(
            static fn ($u) => RequestQueueRequest::fromArray(is_array($u) ? $u : []),
            array_values($rawUnprocessed)
        );
        return new BatchAddResult($processed, $unprocessed);
    }

    /**
     * @param list<RequestQueueRequest>        $chunk
     * @param list<RequestQueueOperationInfo>  $processed
     * @return list<RequestQueueRequest>
     */
    private static function requestsNotYetProcessed(array $chunk, array $processed): array
    {
        $processedKeys = [];
        foreach ($processed as $info) {
            $key = $info->getUniqueKey();
            if ($key !== null) {
                $processedKeys[$key] = true;
            }
        }
        $remaining = [];
        foreach ($chunk as $request) {
            if (!isset($processedKeys[(string) $request->getUniqueKey()])) {
                $remaining[] = $request;
            }
        }
        return $remaining;
    }

    private static function sleepBackoff(int $attempt, int $minDelayMillis): void
    {
        if ($minDelayMillis <= 0) {
            return;
        }
        // (1 + random) * 2^attempt * minDelay — exponential backoff with jitter, matching the reference.
        $factor = (1 + mt_rand() / mt_getrandmax()) * (2 ** $attempt);
        $delayMillis = (int) floor($factor * $minDelayMillis);
        usleep($delayMillis * 1000);
    }

    /**
     * Deletes multiple requests in a single call. Each entry identifies a request to delete: set
     * either {@see RequestQueueRequest::setId()} or {@see RequestQueueRequest::setUniqueKey()} (other
     * fields, if present, are ignored by the API).
     *
     * Unlike {@see batchAddRequests()}, this does not chunk oversized input: the API caps a single
     * batch at 25 requests (matching the reference client), so a larger input is rejected up front
     * rather than silently split (a delete is idempotent, so callers can simply call this again per
     * chunk).
     *
     * @param list<RequestQueueRequest> $requests
     * @throws InvalidArgumentException if {@code $requests} is empty or exceeds the per-call limit
     */
    public function batchDeleteRequests(array $requests): BatchDeleteResult
    {
        $requests = array_values($requests);
        if ($requests === []) {
            throw new InvalidArgumentException('batchDeleteRequests: $requests must not be empty');
        }
        if (count($requests) > self::MAX_REQUESTS_PER_BATCH) {
            throw new InvalidArgumentException(sprintf(
                'batchDeleteRequests: got %d requests, which exceeds the maximum of %d per call',
                count($requests),
                self::MAX_REQUESTS_PER_BATCH
            ));
        }

        $params = $this->applyClientKey(new QueryParams());
        $payload = array_map(static fn (RequestQueueRequest $r) => $r->toArray(), $requests);
        return BatchDeleteResult::fromData($this->ctx->deleteWithBody('requests/batch', $params, $payload));
    }

    /**
     * Lists the queue's requests with pagination.
     */
    public function listRequests(?ListRequestsOptions $options = null): RequestQueueRequestsPage
    {
        $options ??= new ListRequestsOptions();
        $options->validate();
        $params = new QueryParams();
        $options->appendTo($params);
        $this->applyClientKey($params);
        return RequestQueueRequestsPage::fromData($this->ctx->getResourceRequired('requests', $params));
    }

    /**
     * Extends the lock on a request by {@code $lockSecs} seconds. If {@code $forefront} is true, the
     * request is moved to the front when its lock expires.
     */
    public function prolongRequestLock(string $id, int $lockSecs, bool $forefront = false): RequestLockInfo
    {
        $params = new QueryParams();
        $params->addInt('lockSecs', $lockSecs)->addBool('forefront', $forefront);
        $this->applyClientKey($params);
        $url = $this->ctx->mergedParams($params)
            ->applyToUrl($this->ctx->subUrl('requests/' . ResourceContext::encodePathSegment($id) . '/lock'));
        $response = $this->http->call('PUT', $url, null, '', timeoutSecs: $this->timeoutSecs);
        return RequestLockInfo::fromData(Json::decodeData((string) $response->getBody()));
    }

    /**
     * Releases the lock on a request. If {@code $forefront} is true, the request is moved to the
     * front of the queue.
     */
    public function deleteRequestLock(string $id, bool $forefront = false): void
    {
        $params = new QueryParams();
        $params->addBool('forefront', $forefront);
        $this->applyClientKey($params);
        $url = $this->ctx->mergedParams($params)
            ->applyToUrl($this->ctx->subUrl('requests/' . ResourceContext::encodePathSegment($id) . '/lock'));
        try {
            $this->http->call('DELETE', $url, timeoutSecs: $this->timeoutSecs);
        } catch (ApifyApiException $e) {
            if (!HttpClientCore::isNotFound($e)) {
                throw $e;
            }
        }
    }

    /** Releases all locks the client holds on this queue's requests. */
    public function unlockRequests(): UnlockRequestsResult
    {
        $params = $this->applyClientKey(new QueryParams());
        return UnlockRequestsResult::fromData($this->ctx->postWithBody('requests/unlock', $params, null, ''));
    }

    /**
     * Lazily iterates over the queue's requests, transparently following pagination.
     *
     * With no options it fetches pages of up to {@see PaginateRequestsOptions::DEFAULT_MAX_PAGE_LIMIT}
     * requests until the queue is exhausted. The options mirror the reference client: {@code limit}
     * caps the total number of requests yielded across all pages, {@code maxPageLimit} caps the page
     * size, {@code exclusiveStartId}/{@code cursor} choose the starting point (first page only), and
     * {@code filter} restricts to locked/pending requests.
     *
     * @return Generator<int,RequestQueueRequest>
     */
    public function paginateRequests(?PaginateRequestsOptions $options = null): Generator
    {
        $options ??= new PaginateRequestsOptions();
        $options->validate();

        $maxPageLimit = $options->maxPageLimit ?? PaginateRequestsOptions::DEFAULT_MAX_PAGE_LIMIT;
        // Total cap across all pages. null or 0 means "iterate all" (the API treats limit=0 as
        // unset). Normalizing 0 -> null here matches iterateKeys and the offset paginator's minLimit
        // convention, and stops a per-page limit=0 from short-circuiting the iteration after one page.
        $limit = ($options->limit !== null && $options->limit > 0) ? $options->limit : null;
        $nextCursor = $options->cursor;
        $nextExclusiveStartId = $options->exclusiveStartId; // used for the first page only
        $iterated = 0;

        while (true) {
            $pageLimit = $limit !== null ? min($maxPageLimit, $limit - $iterated) : $maxPageLimit;

            $page = $this->listRequests(new ListRequestsOptions(
                limit: $pageLimit,
                exclusiveStartId: $nextExclusiveStartId,
                cursor: $nextCursor,
                filter: $options->filter,
            ));

            $items = $page->getItems();
            if ($items === []) {
                return;
            }
            foreach ($items as $item) {
                yield $item;
            }
            $iterated += count($items);

            $nextCursor = $page->getNextCursor();
            if (($limit !== null && $iterated >= $limit) || $nextCursor === null || $nextCursor === '') {
                return;
            }
            // After the first page, paginate purely by cursor.
            $nextExclusiveStartId = null;
        }
    }
}
