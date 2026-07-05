<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\ActorRun;
use Apify\Client\Model\Task;
use Apify\Client\Options\LastRunOptions;
use Apify\Client\Options\TaskStartOptions;

/**
 * A client for a specific Actor task.
 *
 * Tasks are pre-configured Actor runs with stored input. The client provides CRUD methods plus
 * convenience helpers to start/call the task and access its input, runs and webhooks.
 */
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
    public function get(): ?Task
    {
        $data = $this->ctx->getResource('', new QueryParams());
        return is_array($data) ? new Task($data) : null;
    }

    /**
     * Updates the task with the given fields and returns the updated object.
     *
     * @param mixed $newFields any JSON-serializable set of fields to update
     */
    public function update(mixed $newFields): Task
    {
        return new Task($this->ctx->updateResource('', $newFields));
    }

    /** Deletes the task. */
    public function delete(): void
    {
        $this->ctx->deleteResource('');
    }

    /**
     * Starts the task and returns immediately with the created run.
     *
     * @param mixed $input optionally overrides the task's stored input ({@code null} to use it)
     */
    public function start(mixed $input = null, ?TaskStartOptions $options = null): ActorRun
    {
        $params = new QueryParams();
        ($options ?? new TaskStartOptions())->appendTo($params);
        $body = $input === null ? null : Json::encode($input);
        return new ActorRun($this->ctx->postWithBody('runs', $params, $body, ResourceContext::CONTENT_TYPE_JSON));
    }

    /**
     * Starts the task and waits (client-side polling) for it to finish.
     *
     * @param mixed    $input    optionally overrides the task's stored input
     * @param int|null $waitSecs bounds the wait; {@code null} waits indefinitely
     */
    public function call(mixed $input = null, ?TaskStartOptions $options = null, ?int $waitSecs = null): ActorRun
    {
        $run = $this->start($input, $options);
        return $this->root->run((string) $run->getId())->waitForFinish($waitSecs);
    }

    /**
     * Fetches the task's stored input, or {@code null} if none is set.
     *
     * @return mixed
     */
    public function getInput(): mixed
    {
        $response = $this->ctx->getRaw('input', new QueryParams());
        return $response === null ? null : Json::decode((string) $response->getBody());
    }

    /**
     * Replaces the task's stored input and returns the updated input.
     *
     * @param mixed $input any JSON-serializable value
     * @return mixed
     */
    public function updateInput(mixed $input): mixed
    {
        $response = $this->http->call(
            'PUT',
            $this->ctx->subUrl('input'),
            Json::encode($input),
            ResourceContext::CONTENT_TYPE_JSON
        );
        return Json::decode((string) $response->getBody());
    }

    /** Returns a client for the last run of this task, optionally filtered by status and/or origin. */
    public function lastRun(?LastRunOptions $options = null): RunClient
    {
        $client = new RunClient($this->http, $this->ctx->subUrl(''), 'runs', 'last');
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
