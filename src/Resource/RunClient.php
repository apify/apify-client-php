<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\DatasetItemsPage;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\ActorRun;
use Apify\Client\Options\DatasetListItemsOptions;
use Apify\Client\Options\LastRunOptions;
use Apify\Client\Options\LogOptions;
use Apify\Client\Options\MetamorphOptions;
use Apify\Client\Options\RunChargeOptions;
use Apify\Client\Options\RunIterateDatasetItemsOptions;
use Apify\Client\Options\RunResurrectOptions;
use Generator;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/**
 * A client for an Actor run: its CRUD calls, abort, metamorph, reboot, resurrect, charge, and wait, and its default
 * storages and log.
 */
final class RunClient
{
    /** Header the API uses to deduplicate charge requests. */
    private const CHARGE_IDEMPOTENCY_HEADER = 'idempotency-key';

    /** Default number of dataset rows requested per API call by {@see iterateDatasetItems()}. */
    private const ITERATE_DEFAULT_CHUNK_SIZE = 1000;

    /** Default time, in seconds, {@see iterateDatasetItems()} waits for the run to finish between polls. */
    private const ITERATE_DEFAULT_POLL_INTERVAL_SECS = 5;

    private ResourceContext $ctx;

    /** @internal */
    public function __construct(
        private ApifyClient $root,
        private HttpClientCore $http,
        string $baseUrl,
        string $resourcePath,
        private string $id,
    ) {
        $this->ctx = ResourceContext::single($http, $baseUrl, $resourcePath, $id);
    }

    /**
     * Sets the status and origin filters that every call of this client sends. Used by the last-run accessors.
     *
     * @internal
     */
    public function setLastRunParams(LastRunOptions $options): void
    {
        if ($options->status !== null && $options->status !== '') {
            $this->ctx->baseParams->addRaw('status', $options->status);
        }
        if ($options->origin !== null && $options->origin !== '') {
            $this->ctx->baseParams->addRaw('origin', $options->origin);
        }
    }

    /**
     * Fetches the run, or returns {@code null} if it does not exist. With {@code $waitForFinishSecs}, the API
     * holds the response until the run finishes, for at most 60 seconds and less than the request timeout. The
     * request timeout then defaults to that wait plus the {@code short} tier.
     */
    public function get(?int $waitForFinishSecs = null, int|float|string|null $timeoutSecs = null): ?ActorRun
    {
        $params = new QueryParams();
        $params->addInt('waitForFinish', $this->ctx->clampServerWait($waitForFinishSecs));
        $data = $this->ctx->getResource('', $params, $this->ctx->resolveWaitTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT, $waitForFinishSecs));
        return is_array($data) ? new ActorRun($data) : null;
    }

    /**
     * Updates the run and returns it.
     *
     * @param mixed $newFields any JSON-serializable fields
     */
    public function update(mixed $newFields, int|float|string|null $timeoutSecs = null): ActorRun
    {
        return new ActorRun($this->ctx->updateResource('', $newFields, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)));
    }

    /** Deletes the run. */
    public function delete(int|float|string|null $timeoutSecs = null): void
    {
        $this->ctx->deleteResource('', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
    }

    /**
     * Aborts the run. With {@code $gracefully}, the run can finish its current request first. {@code null}
     * leaves the choice to the API, which aborts immediately.
     */
    public function abort(?bool $gracefully = null, int|float|string|null $timeoutSecs = null): ActorRun
    {
        $params = new QueryParams();
        $params->addBool('gracefully', $gracefully);
        return new ActorRun($this->ctx->postWithBody('abort', $params, null, '', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM)));
    }

    /**
     * Transforms the run into a run of another Actor with a new input.
     *
     * @param string $targetActorId the Actor to metamorph into
     * @param mixed  $input         the new input: a JSON-serializable value, a string sent as it is (set a
     *                               matching {@code $options->contentType}), or {@code null} for none
     */
    public function metamorph(
        string $targetActorId,
        mixed $input = null,
        ?MetamorphOptions $options = null,
        int|float|string|null $timeoutSecs = null,
    ): ActorRun {
        $options ??= new MetamorphOptions();
        $params = new QueryParams();
        $params->addString('targetActorId', ResourceContext::toSafeId($targetActorId));
        if ($options->build !== null && $options->build !== '') {
            $params->addString('build', $options->build);
        }
        $body = ResourceContext::encodeInputBody($input);
        return new ActorRun($this->ctx->postWithBody(
            'metamorph',
            $params,
            $body,
            $options->contentTypeOrDefault(),
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM)
        ));
    }

    /** Reboots the run (restarts its container while keeping the same run). */
    public function reboot(int|float|string|null $timeoutSecs = null): ActorRun
    {
        return new ActorRun(
            $this->ctx->postWithBody('reboot', new QueryParams(), null, '', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM))
        );
    }

    /** Resurrects a finished run, starting it again from the beginning. */
    public function resurrect(?RunResurrectOptions $options = null, int|float|string|null $timeoutSecs = null): ActorRun
    {
        $params = new QueryParams();
        ($options ?? new RunResurrectOptions())->appendTo($params);
        return new ActorRun(
            $this->ctx->postWithBody('resurrect', $params, null, '', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM))
        );
    }

    /**
     * Charges for events of a pay-per-event Actor run.
     *
     * The request always carries an idempotency key, so a retried charge is applied once.
     */
    public function charge(RunChargeOptions $options, int|float|string|null $timeoutSecs = null): void
    {
        if ($options->eventName === '') {
            throw new InvalidArgumentException('RunChargeOptions.eventName is required and must not be empty');
        }
        $idempotencyKey = $options->idempotencyKey;
        if ($idempotencyKey === null || $idempotencyKey === '') {
            $idempotencyKey = $this->generateIdempotencyKey($options->eventName);
        }
        $body = ['eventName' => $options->eventName, 'count' => $options->countValue()];
        if ($options->requestId !== null) {
            $body['requestId'] = $options->requestId;
        }
        $this->http->call(
            'POST',
            $this->ctx->subUrl('charge'),
            Json::encode($body),
            ResourceContext::CONTENT_TYPE_JSON,
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT),
            false,
            [self::CHARGE_IDEMPOTENCY_HEADER => $idempotencyKey]
        );
    }

    /** Builds an idempotency key: {@code "{runId}-{eventName}-{millis}-{random}"}. */
    private function generateIdempotencyKey(string $eventName): string
    {
        return sprintf('%s-%s-%d-%d', $this->id, $eventName, (int) round(microtime(true) * 1000), mt_rand(0, 999999));
    }

    /**
     * Polls until the run finishes or {@code $waitSecs} elapse ({@code null} waits indefinitely), and returns
     * the latest run. {@code $timeoutSecs} is the timeout of each poll (default {@code 'noTimeout'}).
     */
    public function waitForFinish(?int $waitSecs = null, int|float|string|null $timeoutSecs = null): ActorRun
    {
        $data = $this->ctx->waitForFinish(
            $waitSecs,
            'run',
            static fn (array $d): bool => (new ActorRun($d))->isTerminal(),
            $timeoutSecs
        );
        return new ActorRun($data);
    }

    /**
     * A client for the default dataset of the run. Last-run filters are inherited.
     *
     * A client without an ID of its own rethrows a 404 from {@code get()} and {@code delete()}, since it cannot
     * tell a missing run from a missing dataset.
     */
    public function dataset(): DatasetClient
    {
        return DatasetClient::fromContext($this->http, $this->datasetContext());
    }

    /**
     * A client for the default key-value store of the run. Last-run filters are inherited.
     *
     * A client without an ID of its own rethrows a 404 from {@code get()} and {@code delete()}, since it cannot
     * tell a missing run from a missing store. Record lookups still
     * return {@code null} for a missing record.
     */
    public function keyValueStore(): KeyValueStoreClient
    {
        return KeyValueStoreClient::nested($this->http, $this->ctx->subUrl(''), 'key-value-store', $this->ctx->baseParams);
    }

    /**
     * A client for the default request queue of the run. Last-run filters are inherited.
     *
     * A client without an ID of its own rethrows a 404 from {@code get()} and {@code delete()}, since it cannot
     * tell a missing run from a missing queue. Request lookups still
     * return {@code null} for a missing request.
     */
    public function requestQueue(): RequestQueueClient
    {
        return RequestQueueClient::nested($this->http, $this->ctx->subUrl(''), 'request-queue', $this->ctx->baseParams);
    }

    /**
     * A client for the log of the run. Last-run filters are inherited.
     *
     * A client without an ID of its own rethrows a 404 from {@code get()}, since it cannot tell a missing run
     * from a missing log.
     */
    public function log(): LogClient
    {
        return LogClient::nested($this->http, $this->ctx->subUrl(''), $this->ctx->baseParams);
    }

    /** Opens a stream of the raw log of the run. Read it to the end. */
    public function getStreamedLog(): StreamInterface
    {
        return $this->log()->stream(new LogOptions(raw: true));
    }

    /**
     * Lazily yields the items of the default dataset while the run is still producing them, and returns once
     * the run has finished and the dataset is drained.
     *
     * While the run is active, each poll yields the rows below the dataset's {@code itemCount}, then waits up to
     * {@code pollIntervalSecs} for the run to finish. A page ends at {@code itemCount}, so it covers exactly the
     * rows it asks for, whatever the filters or {@code unwind} do. {@code itemCount} lags a few seconds behind,
     * so once the run is terminal, the remaining rows are read page by page. A last-run client stays on the run
     * its first request resolved to.
     *
     * @param int|float|string|null $timeoutSecs timeout of each API request (default: the {@code long} tier)
     * @return Generator<int,mixed> the dataset items, decoded like {@see DatasetClient::listItems()}
     */
    public function iterateDatasetItems(?RunIterateDatasetItemsOptions $options = null, int|float|string|null $timeoutSecs = null): Generator
    {
        $options ??= new RunIterateDatasetItemsOptions();
        $timeoutSecs ??= TimeoutTiers::TIER_LONG;
        $pageSize = $options->chunkSize !== null && $options->chunkSize > 0 ? $options->chunkSize : self::ITERATE_DEFAULT_CHUNK_SIZE;
        $pollIntervalSecs = max(0, $options->pollIntervalSecs ?? self::ITERATE_DEFAULT_POLL_INTERVAL_SECS);
        $position = $options->offset ?? 0;
        $end = $options->limit !== null && $options->limit > 0 ? $position + $options->limit : null;

        $run = $this->get(null, $timeoutSecs);
        // A last-run client resolves `runs/last` on every request, so stay on the first run it resolved to.
        $runClient = $run !== null && $run->getId() !== null && $run->getId() !== $this->id ? $this->root->run($run->getId()) : $this;
        // One context serves both the dataset client and the page reads, so they cannot drift apart.
        $itemsCtx = $runClient->datasetContext();
        $datasetClient = DatasetClient::fromContext($this->http, $itemsCtx);
        $requestTimeoutSecs = $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG);

        while (true) {
            $isFinished = $run === null || $run->isTerminal();
            $itemCount = $datasetClient->get($timeoutSecs)?->getItemCount() ?? 0;
            if ($end !== null) {
                $itemCount = min($itemCount, $end);
            }

            while ($position < $itemCount) {
                $pageLimit = min($pageSize, $itemCount - $position);
                $page = DatasetItemsPage::fetch($this->http, $itemsCtx, self::pageOptions($options, $position, $pageLimit), $requestTimeoutSecs);
                foreach ($page->getItems() as $item) {
                    yield $item;
                }
                $position += $pageLimit;
            }

            if ($end !== null && $position >= $end) {
                return;
            }
            if ($isFinished) {
                break;
            }
            $run = $runClient->waitForFinish($pollIntervalSecs, $timeoutSecs);
        }

        // The run has finished but `itemCount` lags the pushed items: read on, a page at a time.
        while (true) {
            $pageLimit = $end !== null ? min($pageSize, $end - $position) : $pageSize;
            $page = DatasetItemsPage::fetch($this->http, $itemsCtx, self::pageOptions($options, $position, $pageLimit), $requestTimeoutSecs);
            foreach ($page->getItems() as $item) {
                yield $item;
            }
            // Only an empty page ends the iteration, since filters can shorten a full one. A page emptied
            // past a lagging `itemCount` reports no scanned rows, so a plain one-row read confirms it.
            $isEmpty = $page->getCount() === 0;
            if ($isEmpty && (!self::hasRowChangingFilter($options) || $this->hasNoItemsAt($datasetClient, $position, $timeoutSecs))) {
                return;
            }
            $position += $pageLimit;
            if ($end !== null && $position >= $end) {
                return;
            }
        }
    }

    /** The dataset options reading one page of a live iteration: its item filters at the given row window. */
    private static function pageOptions(RunIterateDatasetItemsOptions $options, int $offset, int $limit): DatasetListItemsOptions
    {
        return new DatasetListItemsOptions(
            offset: $offset,
            limit: $limit,
            fields: $options->fields,
            omit: $options->omit,
            skipEmpty: $options->skipEmpty,
            skipHidden: $options->skipHidden,
            clean: $options->clean,
            unwind: $options->unwind,
        );
    }

    /** Whether a filter can drop whole rows or multiply them, so that a page's item count is not its row count. */
    private static function hasRowChangingFilter(RunIterateDatasetItemsOptions $options): bool
    {
        return $options->clean === true || $options->skipEmpty === true || ($options->unwind !== null && $options->unwind !== []);
    }

    /** The context of this run's default dataset, inheriting the last-run filters pinned on this client. */
    private function datasetContext(): ResourceContext
    {
        $context = ResourceContext::collection($this->http, $this->ctx->subUrl(''), 'dataset');
        $context->baseParams = $this->ctx->baseParams->copy();
        return $context;
    }

    /** Whether an unfiltered read of one row at {@code $offset} finds nothing. */
    private function hasNoItemsAt(DatasetClient $datasetClient, int $offset, int|float|string $timeoutSecs): bool
    {
        return $datasetClient->listItems(new DatasetListItemsOptions(offset: $offset, limit: 1), $timeoutSecs)->getItems() === [];
    }
}
