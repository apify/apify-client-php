<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\ActorRun;
use Apify\Client\Options\LastRunOptions;
use Apify\Client\Options\LogOptions;
use Apify\Client\Options\MetamorphOptions;
use Apify\Client\Options\RunChargeOptions;
use Apify\Client\Options\RunResurrectOptions;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/**
 * A client for a specific Actor run.
 *
 * It provides CRUD methods plus convenience helpers (abort, metamorph, reboot, resurrect, charge,
 * wait-for-finish) and accessors for the run's default storages and log.
 */
final class RunClient
{
    /** Header the API uses to deduplicate charge requests. */
    private const CHARGE_IDEMPOTENCY_HEADER = 'idempotency-key';

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
     * Pins the {@code status}/{@code origin} query parameters inherited by all calls on this client
     * (used by the last-run accessors). Empty values are skipped.
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
     * Fetches the run, optionally asking the API to wait up to {@code $waitForFinishSecs} seconds
     * (max 60) for the run to reach a terminal state. Returns {@code null} if it does not exist.
     */
    public function get(?int $waitForFinishSecs = null): ?ActorRun
    {
        $params = new QueryParams();
        $params->addInt('waitForFinish', $this->ctx->clampServerWait($waitForFinishSecs));
        $data = $this->ctx->getResource('', $params);
        return is_array($data) ? new ActorRun($data) : null;
    }

    /**
     * Updates the run with the given fields and returns the updated object.
     *
     * @param mixed $newFields any JSON-serializable set of fields to update
     */
    public function update(mixed $newFields): ActorRun
    {
        return new ActorRun($this->ctx->updateResource('', $newFields));
    }

    /** Deletes the run. */
    public function delete(): void
    {
        $this->ctx->deleteResource('');
    }

    /**
     * Aborts the run. If {@code $gracefully} is {@code true}, the run is signalled so it can finish
     * the current request before terminating; {@code false} aborts immediately. {@code null} omits
     * the parameter and lets the server apply its default (immediate abort).
     */
    public function abort(?bool $gracefully = null): ActorRun
    {
        $params = new QueryParams();
        $params->addBool('gracefully', $gracefully);
        return new ActorRun($this->ctx->postWithBody('abort', $params, null, ''));
    }

    /**
     * Transforms the run into a run of another Actor with a new input.
     *
     * @param string $targetActorId the Actor to metamorph into
     * @param mixed  $input         the new input ({@code null} for none)
     */
    public function metamorph(string $targetActorId, mixed $input = null, ?MetamorphOptions $options = null): ActorRun
    {
        $options ??= new MetamorphOptions();
        $params = new QueryParams();
        $params->addString('targetActorId', $targetActorId);
        if ($options->build !== null && $options->build !== '') {
            $params->addString('build', $options->build);
        }
        $body = $input === null ? null : Json::encode($input);
        return new ActorRun($this->ctx->postWithBody('metamorph', $params, $body, $options->contentTypeOrDefault()));
    }

    /** Reboots the run (restarts its container while keeping the same run). */
    public function reboot(): ActorRun
    {
        return new ActorRun($this->ctx->postWithBody('reboot', new QueryParams(), null, ''));
    }

    /** Resurrects a finished run, starting it again from the beginning. */
    public function resurrect(?RunResurrectOptions $options = null): ActorRun
    {
        $params = new QueryParams();
        ($options ?? new RunResurrectOptions())->appendTo($params);
        return new ActorRun($this->ctx->postWithBody('resurrect', $params, null, ''));
    }

    /**
     * Charges for a pay-per-event Actor run: records occurrences of a named event. Only meaningful
     * for runs of pay-per-event Actors.
     *
     * An idempotency key is always sent (auto-generated if not provided), so a charge that is retried
     * by the transport is applied at most once, matching the reference client.
     */
    public function charge(RunChargeOptions $options): void
    {
        if ($options->eventName === '') {
            throw new InvalidArgumentException('RunChargeOptions.eventName is required and must not be empty');
        }
        $idempotencyKey = $options->idempotencyKey;
        if ($idempotencyKey === null || $idempotencyKey === '') {
            $idempotencyKey = $this->generateIdempotencyKey($options->eventName);
        }
        $body = ['eventName' => $options->eventName, 'count' => $options->countValue()];
        $this->http->call(
            'POST',
            $this->ctx->subUrl('charge'),
            Json::encode($body),
            ResourceContext::CONTENT_TYPE_JSON,
            null,
            false,
            [self::CHARGE_IDEMPOTENCY_HEADER => $idempotencyKey]
        );
    }

    /**
     * Builds a per-charge idempotency key of the form {@code "{runId}-{eventName}-{millis}-{random}"}.
     * It need not be cryptographically secure, only unique enough to avoid collisions within a request.
     */
    private function generateIdempotencyKey(string $eventName): string
    {
        return sprintf('%s-%s-%d-%d', $this->id, $eventName, (int) round(microtime(true) * 1000), mt_rand(0, 999999));
    }

    /**
     * Polls until the run reaches a terminal state or {@code $waitSecs} elapses ({@code null} waits
     * indefinitely). Returns the latest run.
     */
    public function waitForFinish(?int $waitSecs = null): ActorRun
    {
        $data = $this->ctx->waitForFinish(
            $waitSecs,
            'run',
            static fn (array $d): bool => (new ActorRun($d))->isTerminal()
        );
        return new ActorRun($data);
    }

    /** A client for this run's default dataset. */
    public function dataset(): DatasetClient
    {
        return DatasetClient::nested($this->http, $this->ctx->subUrl(''), 'dataset');
    }

    /** A client for this run's default key-value store. */
    public function keyValueStore(): KeyValueStoreClient
    {
        return KeyValueStoreClient::nested($this->http, $this->ctx->subUrl(''), 'key-value-store');
    }

    /** A client for this run's default request queue. */
    public function requestQueue(): RequestQueueClient
    {
        return RequestQueueClient::nested($this->http, $this->ctx->subUrl(''), 'request-queue');
    }

    /** A client for accessing this run's log. */
    public function log(): LogClient
    {
        return LogClient::nested($this->http, $this->ctx->subUrl(''));
    }

    /**
     * Opens a live stream of this run's raw log, for convenient log redirection. The caller reads
     * the returned stream to completion.
     */
    public function getStreamedLog(): StreamInterface
    {
        return $this->log()->stream(new LogOptions(raw: true));
    }
}
