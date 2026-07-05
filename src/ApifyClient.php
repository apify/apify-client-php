<?php

declare(strict_types=1);

namespace Apify\Client;

use Apify\Client\Http\GuzzleHttpClient;
use Apify\Client\Http\HttpClientInterface;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\RetryConfig;
use Apify\Client\Model\ActorRun;
use Apify\Client\Options\RequestQueueClientOptions;
use Apify\Client\Resource\ActorClient;
use Apify\Client\Resource\ActorCollectionClient;
use Apify\Client\Resource\BuildClient;
use Apify\Client\Resource\BuildCollectionClient;
use Apify\Client\Resource\DatasetClient;
use Apify\Client\Resource\DatasetCollectionClient;
use Apify\Client\Resource\KeyValueStoreClient;
use Apify\Client\Resource\KeyValueStoreCollectionClient;
use Apify\Client\Resource\LogClient;
use Apify\Client\Resource\RequestQueueClient;
use Apify\Client\Resource\RequestQueueCollectionClient;
use Apify\Client\Resource\RunClient;
use Apify\Client\Resource\RunCollectionClient;
use Apify\Client\Resource\ScheduleClient;
use Apify\Client\Resource\ScheduleCollectionClient;
use Apify\Client\Resource\StoreCollectionClient;
use Apify\Client\Resource\TaskClient;
use Apify\Client\Resource\TaskCollectionClient;
use Apify\Client\Resource\UserClient;
use Apify\Client\Resource\WebhookClient;
use Apify\Client\Resource\WebhookCollectionClient;
use Apify\Client\Resource\WebhookDispatchClient;
use Apify\Client\Resource\WebhookDispatchCollectionClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * The entry point for interacting with the Apify API.
 *
 * <b>Official, but experimental — AI-generated and AI-maintained.</b> This is an official Apify
 * client, but it is experimental: it is generated and maintained by AI. Review the code before
 * relying on it in production and report issues on the repository.
 *
 * Construct it with an API token (and optional settings via named arguments), then obtain resource
 * clients via the accessor methods, e.g. {@see actor()}, {@see dataset()}, {@see run()}.
 *
 * <b>Architecture.</b> The public interface is this class and the resource clients it returns. The
 * replaceable transport is the {@see HttpClientInterface} (default {@see GuzzleHttpClient}); pass a
 * custom one via the {@code httpClient} argument. Cross-cutting behaviour (auth, User-Agent, retries
 * with exponential backoff, timeouts) lives in the internal HTTP client and is applied to every
 * request.
 */
final class ApifyClient
{
    /** Default base URL of the Apify API (without the {@code /v2} suffix). */
    public const DEFAULT_BASE_URL = 'https://api.apify.com';

    public const DEFAULT_MAX_RETRIES = 8;
    public const DEFAULT_MIN_DELAY_MILLIS = 500;
    public const DEFAULT_TIMEOUT_SECS = 360;

    /** Environment variable that signals the client is running on the Apify platform. */
    private const ENV_IS_AT_HOME = 'APIFY_IS_AT_HOME';

    /** Addresses the current user ({@code /users/me}). */
    private const ME_USER_PLACEHOLDER = 'me';

    private HttpClientCore $http;
    private string $baseUrl;
    private string $publicBaseUrl;

    /**
     * @param string|null              $token                        API token, sent as a Bearer token
     * @param string                   $baseUrl                      API base URL; {@code /v2} is appended automatically
     * @param string|null              $publicBaseUrl                base URL for building public, shareable resource
     *                                                               URLs (defaults to {@code $baseUrl}); {@code /v2} appended
     * @param int                      $maxRetries                   maximum retries for failed requests (default 8)
     * @param int                      $minDelayBetweenRetriesMillis minimum delay between retries in ms (default 500)
     * @param int|null                 $maxDelayBetweenRetriesMillis upper bound for the growing inter-retry delay
     *                                                               (defaults to the request timeout)
     * @param int                      $timeoutSecs                  overall per-request timeout in seconds (default 360)
     * @param string|null              $userAgentSuffix              custom suffix appended to the User-Agent header
     * @param HttpClientInterface|null $httpClient                   replaces the default transport (Guzzle)
     * @param RequestFactoryInterface|null $requestFactory           PSR-17 request factory (defaults to Guzzle's)
     * @param StreamFactoryInterface|null  $streamFactory            PSR-17 stream factory (defaults to Guzzle's)
     * @param callable():bool|null     $isAtHomeFn                   test seam overriding the isAtHome flag detection
     */
    public function __construct(
        ?string $token = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?string $publicBaseUrl = null,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $minDelayBetweenRetriesMillis = self::DEFAULT_MIN_DELAY_MILLIS,
        ?int $maxDelayBetweenRetriesMillis = null,
        int $timeoutSecs = self::DEFAULT_TIMEOUT_SECS,
        ?string $userAgentSuffix = null,
        ?HttpClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?callable $isAtHomeFn = null,
    ) {
        $transport = $httpClient ?? new GuzzleHttpClient();
        $factory = new HttpFactory();
        $requestFactory ??= $factory;
        $streamFactory ??= $factory;

        $maxDelayMillis = $maxDelayBetweenRetriesMillis ?? $timeoutSecs * 1000;
        $retry = new RetryConfig(
            $maxRetries,
            (float) $minDelayBetweenRetriesMillis,
            (float) $maxDelayMillis,
            (float) $timeoutSecs,
        );

        $userAgent = self::buildUserAgent($userAgentSuffix, $isAtHomeFn ?? self::defaultIsAtHome(...));
        $this->http = new HttpClientCore($transport, $requestFactory, $streamFactory, $token, $userAgent, $retry);

        $this->baseUrl = self::trimTrailingSlash($baseUrl) . '/v2';
        $publicSource = $publicBaseUrl ?? $baseUrl;
        $this->publicBaseUrl = self::trimTrailingSlash($publicSource) . '/v2';
    }

    /** Returns the {@code User-Agent} header value this client sends. */
    public function getUserAgent(): string
    {
        return $this->http->userAgent();
    }

    /** Returns the fully-qualified API base URL this client targets (including the {@code /v2} suffix). */
    public function getApiBaseUrl(): string
    {
        return $this->baseUrl;
    }

    // ----- Actor accessors -----------------------------------------------------

    /** A client for the Actor collection (list & create Actors). */
    public function actors(): ActorCollectionClient
    {
        return new ActorCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for a specific Actor, addressed by ID or {@code username~name}. */
    public function actor(string $id): ActorClient
    {
        return new ActorClient($this, $this->http, $this->baseUrl, $id);
    }

    // ----- Build accessors -----------------------------------------------------

    /** A client for the Actor build collection (list builds). */
    public function builds(): BuildCollectionClient
    {
        return new BuildCollectionClient($this->http, $this->baseUrl, 'actor-builds');
    }

    /** A client for a specific Actor build. */
    public function build(string $id): BuildClient
    {
        return new BuildClient($this->http, $this->baseUrl, $id);
    }

    // ----- Run accessors -------------------------------------------------------

    /** A client for the Actor run collection (list runs). */
    public function runs(): RunCollectionClient
    {
        return new RunCollectionClient($this->http, $this->baseUrl, 'actor-runs');
    }

    /** A client for a specific Actor run. */
    public function run(string $id): RunClient
    {
        return new RunClient($this->http, $this->baseUrl, 'actor-runs', $id);
    }

    // ----- Dataset accessors ---------------------------------------------------

    /** A client for the dataset collection (list & get-or-create datasets). */
    public function datasets(): DatasetCollectionClient
    {
        return new DatasetCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for a specific dataset, addressed by ID or name. */
    public function dataset(string $id): DatasetClient
    {
        return DatasetClient::forId($this->http, $this->baseUrl, $id)->withPublicBase($this->publicBaseUrl);
    }

    // ----- Key-value store accessors -------------------------------------------

    /** A client for the key-value store collection. */
    public function keyValueStores(): KeyValueStoreCollectionClient
    {
        return new KeyValueStoreCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for a specific key-value store, addressed by ID or name. */
    public function keyValueStore(string $id): KeyValueStoreClient
    {
        return KeyValueStoreClient::forId($this->http, $this->baseUrl, $id)->withPublicBase($this->publicBaseUrl);
    }

    // ----- Request queue accessors ---------------------------------------------

    /** A client for the request queue collection. */
    public function requestQueues(): RequestQueueCollectionClient
    {
        return new RequestQueueCollectionClient($this->http, $this->baseUrl);
    }

    /**
     * A client for a specific request queue, addressed by ID or name. Optionally pass a
     * {@see RequestQueueClientOptions} to set a stable {@code clientKey} and/or a per-request
     * {@code timeoutSecs} for this queue's calls.
     */
    public function requestQueue(string $id, ?RequestQueueClientOptions $options = null): RequestQueueClient
    {
        return RequestQueueClient::forId($this->http, $this->baseUrl, $id, $options);
    }

    // ----- Task accessors ------------------------------------------------------

    /** A client for the Actor task collection (list & create tasks). */
    public function tasks(): TaskCollectionClient
    {
        return new TaskCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for a specific Actor task. */
    public function task(string $id): TaskClient
    {
        return new TaskClient($this, $this->http, $this->baseUrl, $id);
    }

    // ----- Schedule accessors --------------------------------------------------

    /** A client for the schedule collection (list & create schedules). */
    public function schedules(): ScheduleCollectionClient
    {
        return new ScheduleCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for a specific schedule. */
    public function schedule(string $id): ScheduleClient
    {
        return new ScheduleClient($this->http, $this->baseUrl, $id);
    }

    // ----- Webhook accessors ---------------------------------------------------

    /** A client for the webhook collection (list & create webhooks). */
    public function webhooks(): WebhookCollectionClient
    {
        return new WebhookCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for a specific webhook. */
    public function webhook(string $id): WebhookClient
    {
        return new WebhookClient($this->http, $this->baseUrl, $id);
    }

    /** A client for the webhook dispatch collection. */
    public function webhookDispatches(): WebhookDispatchCollectionClient
    {
        return new WebhookDispatchCollectionClient($this->http, $this->baseUrl, 'webhook-dispatches');
    }

    /** A client for a specific webhook dispatch. */
    public function webhookDispatch(string $id): WebhookDispatchClient
    {
        return new WebhookDispatchClient($this->http, $this->baseUrl, $id);
    }

    // ----- Misc accessors ------------------------------------------------------

    /** A client for browsing the Apify Store. */
    public function store(): StoreCollectionClient
    {
        return new StoreCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for accessing a build's or run's log. */
    public function log(string $buildOrRunId): LogClient
    {
        return LogClient::forId($this->http, $this->baseUrl, $buildOrRunId);
    }

    /** A client for the current user ({@code /users/me}). */
    public function me(): UserClient
    {
        return new UserClient($this->http, $this->baseUrl, self::ME_USER_PLACEHOLDER);
    }

    /** A client for a specific user by ID or username. */
    public function user(string $id): UserClient
    {
        return new UserClient($this->http, $this->baseUrl, $id);
    }

    /**
     * Sets the status message of the current Actor run.
     *
     * This convenience method updates the run identified by the {@code ACTOR_RUN_ID} environment
     * variable, so it only works when called from inside an Actor run. If {@code $isTerminal} is
     * true, the message becomes final and won't be overwritten. Throws {@see RuntimeException} if
     * {@code ACTOR_RUN_ID} is not set.
     */
    public function setStatusMessage(string $message, bool $isTerminal = false): ActorRun
    {
        $runId = getenv('ACTOR_RUN_ID');
        if ($runId === false || $runId === '') {
            throw new RuntimeException('ACTOR_RUN_ID environment variable is not set');
        }
        return $this->run($runId)->update([
            'statusMessage' => $message,
            'isStatusMessageTerminal' => $isTerminal,
        ]);
    }

    private static function trimTrailingSlash(string $value): string
    {
        return rtrim($value, '/');
    }

    /**
     * Reports whether the client is running on the Apify platform, by reading the
     * {@code APIFY_IS_AT_HOME} environment variable (set to a non-empty value on the platform).
     */
    private static function defaultIsAtHome(): bool
    {
        $value = getenv(self::ENV_IS_AT_HOME);
        return $value !== false && $value !== '';
    }

    /**
     * Builds the {@code User-Agent} header value mandated by the client requirements:
     * {@code ApifyClient/{version} ({os}; PHP/{phpVersion}); isAtHome/{true|false}}.
     *
     * @param callable():bool $isAtHomeFn
     */
    private static function buildUserAgent(?string $suffix, callable $isAtHomeFn): string
    {
        $os = strtolower(PHP_OS_FAMILY);
        $atHome = $isAtHomeFn() ? 'true' : 'false';
        $ua = sprintf('ApifyClient/%s (%s; PHP/%s); isAtHome/%s', Version::CLIENT_VERSION, $os, PHP_VERSION, $atHome);
        if ($suffix !== null && $suffix !== '') {
            $ua .= '; ' . $suffix;
        }
        return $ua;
    }
}
