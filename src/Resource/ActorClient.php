<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Internal\WaitForResources;
use Apify\Client\Model\Actor;
use Apify\Client\Model\ActorRun;
use Apify\Client\Model\Build;
use Apify\Client\Options\ActorBuildOptions;
use Apify\Client\Options\ActorStartOptions;
use Apify\Client\Options\LastRunOptions;
use Apify\Client\Options\ValidateInputOptions;
use InvalidArgumentException;

/** A client for an Actor: its CRUD calls, start, call, and build, and its runs, builds, versions, and webhooks. */
final class ActorClient
{
    private ResourceContext $ctx;

    private ?ActorServerClient $serverClient = null;

    /** @internal */
    public function __construct(
        private ApifyClient $root,
        private HttpClientCore $http,
        private string $baseUrl,
        private string $id,
    ) {
        $this->ctx = ResourceContext::single($http, $baseUrl, 'actors', $id);
    }

    /** The Actor's ID (or {@code username~name}) as provided. */
    public function getId(): string
    {
        return $this->id;
    }

    /** Fetches the Actor object, or {@code null} if it does not exist. */
    public function get(int|float|string|null $timeoutSecs = null): ?Actor
    {
        $data = $this->ctx->getResource('', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? new Actor($data) : null;
    }

    /**
     * Updates the Actor and returns it.
     *
     * @param mixed $newFields any JSON-serializable fields
     */
    public function update(mixed $newFields, int|float|string|null $timeoutSecs = null): Actor
    {
        return new Actor($this->ctx->updateResource('', $newFields, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)));
    }

    /** Deletes the Actor. */
    public function delete(int|float|string|null $timeoutSecs = null): void
    {
        $this->ctx->deleteResource('', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
    }

    /**
     * Starts the Actor and returns the created run without waiting.
     *
     * @param mixed $input a JSON-serializable value, a string sent as it is (set a matching
     *                      {@code $options->contentType}), or {@code null} for no input
     */
    public function start(
        mixed $input = null,
        ?ActorStartOptions $options = null,
        int|float|string|null $timeoutSecs = null,
    ): ActorRun {
        $options ??= new ActorStartOptions();
        $params = new QueryParams();
        $options->appendTo($params);
        $body = ResourceContext::encodeInputBody($input);
        return WaitForResources::start(
            fn () => new ActorRun($this->ctx->postWithBody(
                'runs',
                $params,
                $body,
                $options->contentTypeOrDefault(),
                $this->ctx->resolveWaitTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM, $options->waitForFinish)
            )),
            $options->waitForResources,
        );
    }

    /**
     * Starts the Actor and polls until it finishes.
     *
     * @param mixed    $input    the input, as for {@see start()}
     * @param int|null $waitSecs bounds the wait ({@code null} waits indefinitely). Retries of a
     *                           {@code $options->waitForResources} start do not count.
     * @param int|float|string|null $timeoutSecs timeout of the start request and of each poll (default
     *                           {@code 'noTimeout'})
     */
    public function call(mixed $input = null, ?ActorStartOptions $options = null, ?int $waitSecs = null, int|float|string|null $timeoutSecs = null): ActorRun
    {
        // The start request and every poll have no request timeout by default.
        $timeoutSecs ??= TimeoutTiers::NO_TIMEOUT;
        $run = $this->start($input, $options, $timeoutSecs);
        return $this->root->run((string) $run->getId())->waitForFinish($waitSecs, $timeoutSecs);
    }

    /**
     * Returns whether {@code input} is valid against the input schema of the Actor.
     *
     * @param mixed $input the input, as for {@see start()}
     */
    public function validateInput(
        mixed $input = null,
        ?ValidateInputOptions $options = null,
        int|float|string|null $timeoutSecs = null,
    ): bool {
        $options ??= new ValidateInputOptions();
        $params = new QueryParams();
        $options->appendTo($params);
        $body = ResourceContext::encodeInputBody($input);
        // The validate-input endpoint returns a bare {"valid": <bool>} object, not the standard
        // {"data": ...} envelope, so parse it without unwrapping.
        $result = $this->ctx->postWithBodyNoEnvelope(
            'validate-input',
            $params,
            $body,
            $options->contentTypeOrDefault(),
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)
        );
        return is_array($result) && ($result['valid'] ?? false) === true;
    }

    /**
     * Builds a version of the Actor and returns the build.
     *
     * @throws InvalidArgumentException if {@code $versionNumber} is empty
     */
    public function build(
        string $versionNumber,
        ?ActorBuildOptions $options = null,
        int|float|string|null $timeoutSecs = null,
    ): Build {
        if ($versionNumber === '') {
            throw new InvalidArgumentException('ActorClient::build: $versionNumber must not be empty');
        }
        $params = new QueryParams();
        $params->addString('version', $versionNumber);
        $options ??= new ActorBuildOptions();
        $options->appendTo($params);
        return new Build($this->ctx->postWithBody(
            'builds',
            $params,
            null,
            ResourceContext::CONTENT_TYPE_JSON,
            $this->ctx->resolveWaitTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM, $options->waitForFinish)
        ));
    }

    /**
     * Returns a client for the default build. {@code $waitForFinish} is how many seconds the API holds the response
     * until the build finishes.
     */
    public function defaultBuild(?int $waitForFinish = null, int|float|string|null $timeoutSecs = null): BuildClient
    {
        $params = new QueryParams();
        // Keep the server-side wait below the cap of a single attempt, consistent with run/build get().
        $params->addInt('waitForFinish', $this->ctx->clampServerWait($waitForFinish));
        $data = $this->ctx->getResourceRequired('builds/default', $params, $this->ctx->resolveWaitTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT, $waitForFinish));
        $build = new Build(is_array($data) ? $data : []);
        return new BuildClient($this->http, $this->baseUrl, (string) $build->getId());
    }

    /** Returns a client for the last run of this Actor, optionally filtered by status and/or origin. */
    public function lastRun(?LastRunOptions $options = null): RunClient
    {
        $client = new RunClient($this->root, $this->http, $this->ctx->subUrl(''), 'runs', 'last');
        $client->setLastRunParams($options ?? new LastRunOptions());
        return $client;
    }

    /** A client for this Actor's build collection. */
    public function builds(): BuildCollectionClient
    {
        return new BuildCollectionClient($this->http, $this->ctx->subUrl(''), 'builds');
    }

    /** A client for this Actor's run collection. */
    public function runs(): RunCollectionClient
    {
        return new RunCollectionClient($this->http, $this->ctx->subUrl(''), 'runs');
    }

    /** A client for a specific version of this Actor. */
    public function version(string $versionNumber): ActorVersionClient
    {
        return new ActorVersionClient($this->http, $this->ctx->subUrl(''), $versionNumber);
    }

    /** A client for this Actor's version collection. */
    public function versions(): ActorVersionCollectionClient
    {
        return new ActorVersionCollectionClient($this->http, $this->ctx->subUrl(''));
    }

    /** A read-only client for this Actor's webhook collection ({@code GET /v2/actors/{id}/webhooks}). */
    public function webhooks(): NestedWebhookCollectionClient
    {
        return new NestedWebhookCollectionClient($this->http, $this->ctx->subUrl(''));
    }

    /**
     * Returns a client for the server interface of the Actor. Every call returns the same client, so the
     * server URL it fetches is reused.
     *
     * @see https://docs.apify.com/platform/actors/running/server-actors
     */
    public function server(): ActorServerClient
    {
        return $this->serverClient ??= new ActorServerClient($this->http, $this->baseUrl, $this->id);
    }
}
