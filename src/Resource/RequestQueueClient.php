<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
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
    /**
     * The API limit on requests per batch call. {@see batchAddRequests()} splits larger input into chunks of
     * this size, {@see batchDeleteRequests()} rejects it.
     */
    private const MAX_REQUESTS_PER_BATCH = 25;

    /** The API's maximum request payload size, 9 MiB. Batches are split to stay under it. */
    private const MAX_PAYLOAD_SIZE_BYTES = 9 * 1024 * 1024;

    /** A margin of 0.01% subtracted from the payload limit. */
    private const PAYLOAD_SAFETY_BUFFER_PERCENT = 0.0001;

    private function __construct(
        private HttpClientCore $http,
        private ResourceContext $ctx,
        private ?string $clientKey,
    ) {
    }

    /**
     * @internal
     *
     * The {@code timeoutSecs} of {@code $options} caps the timeout of every call of the client.
     */
    public static function forId(
        HttpClientCore $http,
        string $baseUrl,
        string $id,
        ?RequestQueueClientOptions $options = null
    ): self {
        $ctx = ResourceContext::single($http, $baseUrl, 'request-queues', $id);
        $ctx->withTimeout($options?->timeoutSecs);
        return new self($http, $ctx, $options?->clientKey);
    }

    /**
     * A client for the default request queue of a run, which has no ID. The {@code $inheritedParams}, such as
     * the status filter of a last-run accessor, are sent with every request.
     *
     * @internal
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
     * A copy of the client that sends {@code $clientKey}. A stable key is needed to operate on the locks the
     * client created, and lets the API detect several clients on one queue.
     */
    public function withClientKey(string $clientKey): self
    {
        return new self($this->http, $this->ctx, $clientKey);
    }

    private function applyClientKey(QueryParams $params): QueryParams
    {
        if ($this->clientKey !== null && $this->clientKey !== '') {
            $params->addString('clientKey', $this->clientKey);
        }
        return $params;
    }

    /**
     * Fetches the queue, or returns {@code null} if it does not exist. A client without an ID, such as
     * {@see \Apify\Client\Resource\RunClient::requestQueue()}, rethrows the 404.
     */
    public function get(int|float|string|null $timeoutSecs = null): ?RequestQueue
    {
        $data = $this->ctx->getResource('', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? new RequestQueue($data) : null;
    }

    /**
     * Updates the queue, for example its name.
     *
     * @param mixed $newFields any JSON-serializable fields
     */
    public function update(mixed $newFields, int|float|string|null $timeoutSecs = null): RequestQueue
    {
        return new RequestQueue($this->ctx->updateResource('', $newFields, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)));
    }

    /** Deletes the queue. A missing queue is ignored, unless the client has no ID. */
    public function delete(int|float|string|null $timeoutSecs = null): void
    {
        $this->ctx->deleteResource('', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
    }

    /** Returns up to {@code $limit} requests from the head of the queue ({@code null} for the API's default). */
    public function listHead(?int $limit = null, int|float|string|null $timeoutSecs = null): RequestQueueHead
    {
        $params = new QueryParams();
        $params->addInt('limit', $limit);
        $this->applyClientKey($params);
        return RequestQueueHead::fromData(
            $this->ctx->getResourceRequired('head', $params, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT))
        );
    }

    /** Adds a request to the queue. If {@code $forefront} is true, it is added to the front. */
    public function addRequest(RequestQueueRequest $request, bool $forefront = false, int|float|string|null $timeoutSecs = null): RequestQueueOperationInfo
    {
        $params = new QueryParams();
        $params->addBool('forefront', $forefront);
        $this->applyClientKey($params);
        $data = $this->ctx->postWithBody(
            'requests',
            $params,
            Json::encode($request->toArray()),
            ResourceContext::CONTENT_TYPE_JSON,
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)
        );
        return new RequestQueueOperationInfo($data);
    }

    /** Fetches a request by ID, or {@code null} if it does not exist. */
    public function getRequest(string $id, int|float|string|null $timeoutSecs = null): ?RequestQueueRequest
    {
        $data = $this->ctx->getResource(
            'requests/' . ResourceContext::encodePathSegment($id),
            new QueryParams(),
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)
        );
        return is_array($data) ? RequestQueueRequest::fromArray($data) : null;
    }

    /** Updates a request, identified by its ID. With {@code $forefront}, it moves to the front of the queue. */
    public function updateRequest(RequestQueueRequest $request, bool $forefront = false, int|float|string|null $timeoutSecs = null): RequestQueueOperationInfo
    {
        $params = new QueryParams();
        $params->addBool('forefront', $forefront);
        $this->applyClientKey($params);
        $url = $this->ctx->mergedParams($params)
            ->applyToUrl($this->ctx->subUrl('requests/' . ResourceContext::encodePathSegment((string) $request->getId())));
        $response = $this->http->call(
            'PUT',
            $url,
            Json::encode($request->toArray()),
            ResourceContext::CONTENT_TYPE_JSON,
            timeoutSecs: $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM)
        );
        $data = Json::decodeData((string) $response->getBody());
        return new RequestQueueOperationInfo(is_array($data) ? $data : []);
    }

    /** Deletes a request by ID. */
    public function deleteRequest(string $id, int|float|string|null $timeoutSecs = null): void
    {
        $params = $this->applyClientKey(new QueryParams());
        $url = $this->ctx->mergedParams($params)
            ->applyToUrl($this->ctx->subUrl('requests/' . ResourceContext::encodePathSegment($id)));
        try {
            $this->http->call('DELETE', $url, timeoutSecs: $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        } catch (ApifyApiException $e) {
            if (!HttpClientCore::isNotFound($e)) {
                throw $e;
            }
        }
    }

    /** Returns up to {@code $limit} requests from the head of the queue and locks them for {@code $lockSecs} seconds. */
    public function listAndLockHead(int $lockSecs, ?int $limit = null, int|float|string|null $timeoutSecs = null): LockedRequestQueueHead
    {
        $params = new QueryParams();
        $params->addInt('lockSecs', $lockSecs)->addInt('limit', $limit);
        $this->applyClientKey($params);
        return LockedRequestQueueHead::fromData(
            $this->ctx->postWithBody('head/lock', $params, null, '', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM))
        );
    }

    /**
     * Adds requests to the queue, at the front with {@code $forefront}.
     *
     * The input is split into batches of at most 25 requests and 9 MiB. Requests the API reports as
     * unprocessed, typically because of rate limiting, are retried with exponential backoff, and the results
     * of the batches are merged.
     *
     * Every request needs a non-empty {@code uniqueKey}, which the retries use to track it. The method does
     * not throw on API errors: when a batch fails, its requests that are not yet processed are returned in
     * {@see BatchAddResult::getUnprocessedRequests()}, together with the results of the earlier batches.
     * Invalid input is rejected before any call.
     *
     * @param list<RequestQueueRequest> $requests
     * @throws InvalidArgumentException if a request has an empty uniqueKey or is too large
     */
    public function batchAddRequests(
        array $requests,
        bool $forefront = false,
        ?BatchAddRequestsOptions $options = null,
        int|float|string|null $timeoutSecs = null
    ): BatchAddResult {
        $options ??= new BatchAddRequestsOptions();
        $requests = array_values($requests);
        $resolvedTimeoutSecs = $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM);

        $payloadSizeLimitBytes = self::MAX_PAYLOAD_SIZE_BYTES
            - (int) ceil(self::MAX_PAYLOAD_SIZE_BYTES * self::PAYLOAD_SAFETY_BUFFER_PERCENT);

        // Serialized once: the sizes decide the batch boundaries and the JSON is reused to build each body.
        // Validated before the first call, so a failure cannot leave the queue partly updated.
        $serialized = [];
        foreach ($requests as $i => $request) {
            $uniqueKey = $request->getUniqueKey();
            if ($uniqueKey === null || $uniqueKey === '') {
                throw new InvalidArgumentException(
                    sprintf('batchAddRequests: the request at index %d is missing a non-empty uniqueKey', $i)
                );
            }
            $json = Json::encode($request->toArray());
            // A request alone in a batch needs the brackets as well. Such a request still fits.
            if (strlen($json) + 2 > $payloadSizeLimitBytes) {
                throw new InvalidArgumentException(sprintf(
                    'batchAddRequests: the request at index %d exceeds the maximum payload size (%d bytes)',
                    $i,
                    $payloadSizeLimitBytes
                ));
            }
            // +1 for the comma or closing bracket that follows this item once it sits inside a batch
            // body alongside others (used by splitIntoBatches() below to size a multi-item batch).
            $serialized[] = ['request' => $request, 'json' => $json, 'bytes' => strlen($json) + 1];
        }

        $merged = new BatchAddResult();
        foreach (self::splitIntoBatches($serialized, self::MAX_REQUESTS_PER_BATCH, $payloadSizeLimitBytes) as $chunk) {
            $merged->merge($this->batchAddChunkWithRetries($chunk, $forefront, $options, $resolvedTimeoutSecs));
        }
        return $merged;
    }

    /**
     * Splits serialized requests into batches of at most {@code $maxCount} items and {@code $maxByteLength}
     * bytes as a JSON array.
     *
     * The {@code bytes} of an item include its trailing comma or closing bracket, so only the opening bracket
     * is added here.
     *
     * @param list<array{request: RequestQueueRequest, json: string, bytes: int}> $items
     * @return list<list<array{request: RequestQueueRequest, json: string, bytes: int}>>
     */
    private static function splitIntoBatches(array $items, int $maxCount, int $maxByteLength): array
    {
        $batches = [];
        $batch = [];
        $byteLength = 1; // the opening bracket "["
        foreach ($items as $item) {
            if ($batch !== [] && (count($batch) >= $maxCount || $byteLength + $item['bytes'] > $maxByteLength)) {
                $batches[] = $batch;
                $batch = [];
                $byteLength = 1;
            }
            $batch[] = $item;
            $byteLength += $item['bytes'];
        }
        if ($batch !== []) {
            $batches[] = $batch;
        }
        return $batches;
    }

    /**
     * @param list<array{request: RequestQueueRequest, json: string, bytes: int}> $chunk
     */
    private function batchAddChunkWithRetries(array $chunk, bool $forefront, BatchAddRequestsOptions $options, ?float $timeoutSecs): BatchAddResult
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
                $response = $this->batchAddChunk($remaining, $forefront, $timeoutSecs);
            } catch (ApifyApiException) {
                // The requests of this batch that are not yet processed are reported as unprocessed, so the
                // results of the earlier batches are kept.
                $unprocessed = array_column(self::itemsNotYetProcessed($chunk, $processed), 'request');
                break;
            }
            $processed = array_merge($processed, $response->getProcessedRequests());
            // Only requests the API reports as unprocessed in this SUCCESSFUL response are retried.
            $unprocessed = $response->getUnprocessedRequests();
            $remaining = self::itemsNotYetProcessed($chunk, $processed);
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
     * Sends one serialized batch. Its JSON is joined into the body as it is, also on a retry.
     *
     * @param list<array{request: RequestQueueRequest, json: string, bytes: int}> $chunk
     */
    private function batchAddChunk(array $chunk, bool $forefront, ?float $timeoutSecs): BatchAddResult
    {
        $params = new QueryParams();
        $params->addBool('forefront', $forefront);
        $this->applyClientKey($params);
        $body = '[' . implode(',', array_column($chunk, 'json')) . ']';
        $data = $this->ctx->postWithBody('requests/batch', $params, $body, ResourceContext::CONTENT_TYPE_JSON, $timeoutSecs);

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
     * @param list<array{request: RequestQueueRequest, json: string, bytes: int}> $chunk
     * @param list<RequestQueueOperationInfo>                                     $processed
     * @return list<array{request: RequestQueueRequest, json: string, bytes: int}>
     */
    private static function itemsNotYetProcessed(array $chunk, array $processed): array
    {
        $processedKeys = [];
        foreach ($processed as $info) {
            $key = $info->getUniqueKey();
            if ($key !== null) {
                $processedKeys[$key] = true;
            }
        }
        $remaining = [];
        foreach ($chunk as $item) {
            if (!isset($processedKeys[(string) $item['request']->getUniqueKey()])) {
                $remaining[] = $item;
            }
        }
        return $remaining;
    }

    private static function sleepBackoff(int $attempt, int $minDelayMillis): void
    {
        if ($minDelayMillis <= 0) {
            return;
        }
        // Exponential backoff with jitter: (1 + random) * 2^attempt * minDelay.
        $factor = (1 + mt_rand() / mt_getrandmax()) * (2 ** $attempt);
        $delayMillis = (int) floor($factor * $minDelayMillis);
        usleep($delayMillis * 1000);
    }

    /**
     * Deletes requests in one call. Each one is identified by its {@see RequestQueueRequest::setId()} or
     * {@see RequestQueueRequest::setUniqueKey()}.
     *
     * Input above the limit of 25 requests is rejected instead of split. A delete can simply be repeated per
     * batch.
     *
     * @param list<RequestQueueRequest> $requests
     * @throws InvalidArgumentException if {@code $requests} is empty, above the limit, or has an entry
     *         without an id and a uniqueKey
     */
    public function batchDeleteRequests(array $requests, int|float|string|null $timeoutSecs = null): BatchDeleteResult
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
        foreach ($requests as $i => $request) {
            $id = $request->getId();
            $uniqueKey = $request->getUniqueKey();
            if (($id === null || $id === '') && ($uniqueKey === null || $uniqueKey === '')) {
                throw new InvalidArgumentException(sprintf(
                    'batchDeleteRequests: the request at index %d must have a non-empty id or uniqueKey',
                    $i
                ));
            }
        }

        $params = $this->applyClientKey(new QueryParams());
        $payload = array_map(static fn (RequestQueueRequest $r) => $r->toArray(), $requests);
        return BatchDeleteResult::fromData(
            $this->ctx->deleteWithBody('requests/batch', $params, $payload, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT))
        );
    }

    /** Lists the requests of the queue. */
    public function listRequests(?ListRequestsOptions $options = null, int|float|string|null $timeoutSecs = null): RequestQueueRequestsPage
    {
        $options ??= new ListRequestsOptions();
        $options->validate();
        $params = new QueryParams();
        $options->appendTo($params);
        $this->applyClientKey($params);
        return RequestQueueRequestsPage::fromData(
            $this->ctx->getResourceRequired('requests', $params, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM))
        );
    }

    /** Extends the lock of a request by {@code $lockSecs} seconds. With {@code $forefront}, it moves to the front when the lock expires. */
    public function prolongRequestLock(
        string $id,
        int $lockSecs,
        bool $forefront = false,
        int|float|string|null $timeoutSecs = null
    ): RequestLockInfo {
        $params = new QueryParams();
        $params->addInt('lockSecs', $lockSecs)->addBool('forefront', $forefront);
        $this->applyClientKey($params);
        $url = $this->ctx->mergedParams($params)
            ->applyToUrl($this->ctx->subUrl('requests/' . ResourceContext::encodePathSegment($id) . '/lock'));
        $response = $this->http->call('PUT', $url, null, '', timeoutSecs: $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM));
        return RequestLockInfo::fromData(Json::decodeData((string) $response->getBody()));
    }

    /** Releases the lock of a request. With {@code $forefront}, it moves to the front of the queue. */
    public function deleteRequestLock(string $id, bool $forefront = false, int|float|string|null $timeoutSecs = null): void
    {
        $params = new QueryParams();
        $params->addBool('forefront', $forefront);
        $this->applyClientKey($params);
        $url = $this->ctx->mergedParams($params)
            ->applyToUrl($this->ctx->subUrl('requests/' . ResourceContext::encodePathSegment($id) . '/lock'));
        try {
            $this->http->call('DELETE', $url, timeoutSecs: $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        } catch (ApifyApiException $e) {
            if (!HttpClientCore::isNotFound($e)) {
                throw $e;
            }
        }
    }

    /** Releases all locks the client holds on this queue's requests. */
    public function unlockRequests(int|float|string|null $timeoutSecs = null): UnlockRequestsResult
    {
        $params = $this->applyClientKey(new QueryParams());
        return UnlockRequestsResult::fromData(
            $this->ctx->postWithBody('requests/unlock', $params, null, '', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG))
        );
    }

    /**
     * Lazily yields the requests of the queue, following the pages.
     *
     * Without options, the pages hold up to {@see PaginateRequestsOptions::DEFAULT_MAX_PAGE_LIMIT} requests.
     * {@code limit} caps the total, {@code maxPageLimit} the page size, {@code exclusiveStartId} and
     * {@code cursor} set the start of the first page, and {@code filter} selects locked or pending requests.
     *
     * @return Generator<int,RequestQueueRequest>
     */
    public function paginateRequests(?PaginateRequestsOptions $options = null, int|float|string|null $timeoutSecs = null): Generator
    {
        $options ??= new PaginateRequestsOptions();
        $options->validate();

        $maxPageLimit = $options->maxPageLimit ?? PaginateRequestsOptions::DEFAULT_MAX_PAGE_LIMIT;
        // A limit of 0 means no limit, as for the API.
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
            ), $timeoutSecs);

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
