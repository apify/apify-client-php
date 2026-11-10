<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Internal\WaitForResources;
use Apify\Client\Model\ActorRun;
use Apify\Client\Model\Task;
use Apify\Client\Options\LastRunOptions;
use Apify\Client\Options\TaskStartOptions;

/** A client for a task, an Actor run with a stored input: its CRUD calls, start and call, and its input, runs and webhooks. */
final class TaskClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(
        private ApifyClient $root,
        private HttpClientCore $http,
        string $baseUrl,
        string $id,
    ) {
        $this->ctx = ResourceContext::single($http, $baseUrl, 'actor-tasks', $id);
    }

    /** Fetches the task object, or {@code null} if it does not exist. */
    public function get(int|float|string|null $timeoutSecs = null): ?Task
    {
        $data = $this->ctx->getResource('', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? new Task($data) : null;
    }

    /**
     * Updates the task and returns it.
     *
     * @param mixed $newFields any JSON-serializable fields
     */
    public function update(mixed $newFields, int|float|string|null $timeoutSecs = null): Task
    {
        return new Task($this->ctx->updateResource('', $newFields, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)));
    }

    /** Deletes the task. */
    public function delete(int|float|string|null $timeoutSecs = null): void
    {
        $this->ctx->deleteResource('', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
    }

    /**
     * Publishes the task on its public landing page by setting {@code isPublic}. Needs write permission to the
     * Actor of the task.
     *
     * The Actor must be public and the task needs {@code publicConfig.inputSchemaFields} and
     * {@code publicConfig.datasetView}. An Actor can have 10 published tasks and an account 100. If a condition
     * fails, nothing changes. Publishing a published task does nothing.
     */
    public function publish(): Task
    {
        return $this->update(['isPublic' => true]);
    }

    /**
     * Unpublishes the task by setting {@code isPublic}. Needs write permission to the Actor of the task. The
     * {@code publicConfig} is kept, so the task can be published again. Unpublishing an unpublished task does
     * nothing.
     */
    public function unpublish(): Task
    {
        return $this->update(['isPublic' => false]);
    }

    /**
     * Starts the task and returns the created run without waiting.
     *
     * @param mixed $input overrides the stored input ({@code null} keeps it)
     */
    public function start(
        mixed $input = null,
        ?TaskStartOptions $options = null,
        int|float|string|null $timeoutSecs = null,
    ): ActorRun {
        $options ??= new TaskStartOptions();
        $params = new QueryParams();
        $options->appendTo($params);
        $body = $input === null ? null : Json::encode($input);
        return WaitForResources::start(
            fn () => new ActorRun($this->ctx->postWithBody(
                'runs',
                $params,
                $body,
                ResourceContext::CONTENT_TYPE_JSON,
                $this->ctx->resolveWaitTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM, $options->waitForFinish)
            )),
            $options->waitForResources,
        );
    }

    /**
     * Starts the task and polls until it finishes.
     *
     * @param mixed    $input    overrides the stored input
     * @param int|null $waitSecs bounds the wait ({@code null} waits indefinitely). Retries of a
     *                           {@code $options->waitForResources} start do not count.
     * @param int|float|string|null $timeoutSecs timeout of the start request and of each poll (default
     *                           {@code 'noTimeout'})
     */
    public function call(mixed $input = null, ?TaskStartOptions $options = null, ?int $waitSecs = null, int|float|string|null $timeoutSecs = null): ActorRun
    {
        // The start request and every poll have no request timeout by default.
        $timeoutSecs ??= TimeoutTiers::NO_TIMEOUT;
        $run = $this->start($input, $options, $timeoutSecs);
        return $this->root->run((string) $run->getId())->waitForFinish($waitSecs, $timeoutSecs);
    }

    /**
     * Fetches the stored input of the task. A 404 is always thrown, never turned into {@code null}. A
     * {@code null} result means the input itself is {@code null} or empty.
     *
     * @return mixed
     */
    public function getInput(int|float|string|null $timeoutSecs = null): mixed
    {
        $response = $this->ctx->getRawRequired('input', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return Json::decode((string) $response->getBody());
    }

    /**
     * Replaces the stored input and returns the updated input.
     *
     * @param mixed $input any JSON-serializable value
     * @return mixed
     */
    public function updateInput(mixed $input, int|float|string|null $timeoutSecs = null): mixed
    {
        $response = $this->http->call(
            'PUT',
            $this->ctx->subUrl('input'),
            Json::encode($input),
            ResourceContext::CONTENT_TYPE_JSON,
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)
        );
        return Json::decode((string) $response->getBody());
    }

    /** Returns a client for the last run of this task, optionally filtered by status and/or origin. */
    public function lastRun(?LastRunOptions $options = null): RunClient
    {
        $client = new RunClient($this->root, $this->http, $this->ctx->subUrl(''), 'runs', 'last');
        $client->setLastRunParams($options ?? new LastRunOptions());
        return $client;
    }

    /** A client for this task's run collection. */
    public function runs(): RunCollectionClient
    {
        return new RunCollectionClient($this->http, $this->ctx->subUrl(''), 'runs');
    }

    /** A read-only client for this task's webhook collection ({@code GET /v2/actor-tasks/{id}/webhooks}). */
    public function webhooks(): NestedWebhookCollectionClient
    {
        return new NestedWebhookCollectionClient($this->http, $this->ctx->subUrl(''));
    }
}
