<?php

declare(strict_types=1);

namespace Apify\Client;

use Apify\Client\Http\GuzzleHttpClient;
use Apify\Client\Http\HttpClientInterface;
use Apify\Client\Http\HttpCompressorInterface;
use Apify\Client\Internal\Compression;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Platform;
use Apify\Client\Internal\RetryConfig;
use Apify\Client\Internal\TimeoutTiers;
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
 * The entry point to the Apify API. Create it with an API token, then get a resource client from one of
 * its accessors, for example {@see actor()}, {@see dataset()} or {@see run()}.
 *
 * Requests go through an {@see HttpClientInterface}, by default {@see GuzzleHttpClient}. Pass another one as
 * {@code httpClient}.
 */
final class ApifyClient
{
    /** The default API base URL, without the {@code /v2} suffix. */
    public const DEFAULT_BASE_URL = 'https://api.apify.com';

    public const DEFAULT_MAX_RETRIES = 8;
    public const DEFAULT_MIN_DELAY_MILLIS = 500;
    public const DEFAULT_TIMEOUT_SECS = 360;

    /** The API version path, appended to the base URLs when absent. */
    private const API_VERSION_PATH = '/v2';

    /** Set to a non-empty value on the Apify platform. */
    private const ENV_IS_AT_HOME = 'APIFY_IS_AT_HOME';

    /** The ID that addresses the current user. */
    private const ME_USER_PLACEHOLDER = 'me';

    private HttpClientCore $http;
    private string $baseUrl;
    private string $publicBaseUrl;

    /**
     * @param string|null              $token                        API token, sent as a Bearer token
     * @param string                   $baseUrl                      API base URL, with or without {@code /v2}
     * @param string|null              $publicBaseUrl                base URL of public resource URLs (defaults to
     *                                                               {@code $baseUrl})
     * @param int                      $maxRetries                   maximum retries for failed requests (default 8)
     * @param int                      $minDelayBetweenRetriesMillis minimum delay between retries in ms (default 500)
     * @param int|null                 $maxDelayBetweenRetriesMillis upper bound for the growing inter-retry delay
     *                                                               (defaults to the request timeout)
     * @param int                      $timeoutSecs                  overall per-request timeout in seconds (default
     *                                                               360); the default of the {@code long} tier and of
     *                                                               {@code $timeoutMaxSecs}
     * @param int|null                 $timeoutShortSecs             timeout of the {@code short} tier, for metadata
     *                                                               reads and writes (default 5)
     * @param int|null                 $timeoutMediumSecs            timeout of the {@code medium} tier, for listing,
     *                                                               batch and trigger calls (default 30)
     * @param int|null                 $timeoutLongSecs              timeout of the {@code long} tier, for downloads,
     *                                                               uploads and streaming (default {@code $timeoutSecs})
     * @param int|null                 $timeoutMaxSecs               cap on the timeout of any attempt, per-call
     *                                                               overrides and growth across retries included
     *                                                               (default {@code $timeoutSecs})
     * @param string|null              $userAgentSuffix              custom suffix appended to the User-Agent header
     * @param HttpClientInterface|null $httpClient                   replaces the default transport (Guzzle)
     * @param RequestFactoryInterface|null $requestFactory           PSR-17 request factory (defaults to Guzzle's)
     * @param StreamFactoryInterface|null  $streamFactory            PSR-17 stream factory (defaults to Guzzle's)
     * @param callable():bool|null     $isAtHomeFn                   replaces the detection of the {@code isAtHome} flag
     * @param string|HttpCompressorInterface|null $compression       request-body compression: {@code 'brotli'},
     *                                                               {@code 'gzip'}, {@code 'zstd'} or a custom compressor,
     *                                                               which also sets the quality. {@code null} picks brotli
     *                                                               when its extension is loaded, gzip otherwise.
     */
    public function __construct(
        ?string $token = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?string $publicBaseUrl = null,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $minDelayBetweenRetriesMillis = self::DEFAULT_MIN_DELAY_MILLIS,
        ?int $maxDelayBetweenRetriesMillis = null,
        int $timeoutSecs = self::DEFAULT_TIMEOUT_SECS,
        ?int $timeoutShortSecs = null,
        ?int $timeoutMediumSecs = null,
        ?int $timeoutLongSecs = null,
        ?int $timeoutMaxSecs = null,
        ?string $userAgentSuffix = null,
        ?HttpClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?callable $isAtHomeFn = null,
        string|HttpCompressorInterface|null $compression = null,
    ) {
        $transport = $httpClient ?? new GuzzleHttpClient();
        $factory = new HttpFactory();
        $requestFactory ??= $factory;
        $streamFactory ??= $factory;
        $compressor = self::resolveCompressor($compression);

        $maxDelayMillis = $maxDelayBetweenRetriesMillis ?? $timeoutSecs * 1000;
        $tiers = TimeoutTiers::create(
            (float) $timeoutSecs,
            $timeoutShortSecs !== null ? (float) $timeoutShortSecs : null,
            $timeoutMediumSecs !== null ? (float) $timeoutMediumSecs : null,
            $timeoutLongSecs !== null ? (float) $timeoutLongSecs : null,
            $timeoutMaxSecs !== null ? (float) $timeoutMaxSecs : null,
        );
        $retry = new RetryConfig(
            $maxRetries,
            (float) $minDelayBetweenRetriesMillis,
            (float) $maxDelayMillis,
            (float) $timeoutSecs,
            $tiers,
        );

        $userAgent = self::buildUserAgent($userAgentSuffix, $isAtHomeFn ?? self::defaultIsAtHome(...));
        $this->http = new HttpClientCore($transport, $requestFactory, $streamFactory, $token, $userAgent, $retry, $compressor);

        $this->baseUrl = self::toApiBaseUrl($baseUrl);
        $this->publicBaseUrl = self::toApiBaseUrl($publicBaseUrl ?? $baseUrl);
    }

    /** The {@code User-Agent} header value of the requests. */
    public function getUserAgent(): string
    {
        return $this->http->userAgent();
    }

    /** The API base URL, including {@code /v2}. */
    public function getApiBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** A client for the Actor collection. */
    public function actors(): ActorCollectionClient
    {
        return new ActorCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for a specific Actor, addressed by ID or {@code username~name}. */
    public function actor(string $id): ActorClient
    {
        return new ActorClient($this, $this->http, $this->baseUrl, $id);
    }

    /** A client for the build collection. */
    public function builds(): BuildCollectionClient
    {
        return new BuildCollectionClient($this->http, $this->baseUrl, 'actor-builds');
    }

    /** A client for a build. */
    public function build(string $id): BuildClient
    {
        return new BuildClient($this->http, $this->baseUrl, $id);
    }

    /** A client for the run collection. */
    public function runs(): RunCollectionClient
    {
        return new RunCollectionClient($this->http, $this->baseUrl, 'actor-runs');
    }

    /** A client for a run. */
    public function run(string $id): RunClient
    {
        return new RunClient($this, $this->http, $this->baseUrl, 'actor-runs', $id);
    }

    /** A client for the dataset collection. */
    public function datasets(): DatasetCollectionClient
    {
        return new DatasetCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for a dataset, addressed by ID or name. */
    public function dataset(string $id): DatasetClient
    {
        return DatasetClient::forId($this->http, $this->baseUrl, $id)->withPublicBase($this->publicBaseUrl);
    }

    /** A client for the key-value store collection. */
    public function keyValueStores(): KeyValueStoreCollectionClient
    {
        return new KeyValueStoreCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for a key-value store, addressed by ID or name. */
    public function keyValueStore(string $id): KeyValueStoreClient
    {
        return KeyValueStoreClient::forId($this->http, $this->baseUrl, $id)->withPublicBase($this->publicBaseUrl);
    }

    /** A client for the request queue collection. */
    public function requestQueues(): RequestQueueCollectionClient
    {
        return new RequestQueueCollectionClient($this->http, $this->baseUrl);
    }

    /**
     * A client for a request queue, addressed by ID or name. The options set a stable {@code clientKey} and a
     * timeout for the calls of this client.
     */
    public function requestQueue(string $id, ?RequestQueueClientOptions $options = null): RequestQueueClient
    {
        return RequestQueueClient::forId($this->http, $this->baseUrl, $id, $options);
    }

    /** A client for the task collection. */
    public function tasks(): TaskCollectionClient
    {
        return new TaskCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for a task. */
    public function task(string $id): TaskClient
    {
        return new TaskClient($this, $this->http, $this->baseUrl, $id);
    }

    /** A client for the schedule collection. */
    public function schedules(): ScheduleCollectionClient
    {
        return new ScheduleCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for a schedule. */
    public function schedule(string $id): ScheduleClient
    {
        return new ScheduleClient($this->http, $this->baseUrl, $id);
    }

    /** A client for the webhook collection. */
    public function webhooks(): WebhookCollectionClient
    {
        return new WebhookCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for a webhook. */
    public function webhook(string $id): WebhookClient
    {
        return new WebhookClient($this->http, $this->baseUrl, $id);
    }

    /** A client for the webhook dispatch collection. */
    public function webhookDispatches(): WebhookDispatchCollectionClient
    {
        return new WebhookDispatchCollectionClient($this->http, $this->baseUrl, 'webhook-dispatches');
    }

    /** A client for a webhook dispatch. */
    public function webhookDispatch(string $id): WebhookDispatchClient
    {
        return new WebhookDispatchClient($this->http, $this->baseUrl, $id);
    }

    /** A client for the Apify Store. */
    public function store(): StoreCollectionClient
    {
        return new StoreCollectionClient($this->http, $this->baseUrl);
    }

    /** A client for the log of a build or run. */
    public function log(string $buildOrRunId): LogClient
    {
        return LogClient::forId($this->http, $this->baseUrl, $buildOrRunId);
    }

    /** A client for the user the token belongs to. */
    public function me(): UserClient
    {
        return new UserClient($this->http, $this->baseUrl, self::ME_USER_PLACEHOLDER);
    }

    /** A client for a user, addressed by ID or username. */
    public function user(string $id): UserClient
    {
        return new UserClient($this->http, $this->baseUrl, $id);
    }

    /**
     * Sets the status message of the run in the {@code ACTOR_RUN_ID} environment variable. A terminal message
     * is final and is not overwritten.
     *
     * @throws RuntimeException if {@code ACTOR_RUN_ID} is not set
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

    /** Resolves the {@code compression} option to a compressor, or {@code null} for the default choice. */
    private static function resolveCompressor(string|HttpCompressorInterface|null $compression): ?HttpCompressorInterface
    {
        return Compression::resolveCompressor($compression, 'ApifyClient');
    }

    /** Appends {@code /v2} to a URL that does not end with it. Another version suffix is left alone. */
    private static function toApiBaseUrl(string $url): string
    {
        $path = self::trimTrailingSlash($url);
        return str_ends_with($path, self::API_VERSION_PATH) ? $path : $path . self::API_VERSION_PATH;
    }

    /** Whether the client runs on the Apify platform. */
    private static function defaultIsAtHome(): bool
    {
        $value = getenv(self::ENV_IS_AT_HOME);
        return $value !== false && $value !== '';
    }

    /**
     * Builds the {@code User-Agent} value: {@code ApifyClient/{version} ({os}; PHP/{phpVersion}); isAtHome/{true|false}}.
     *
     * @param callable():bool $isAtHomeFn
     */
    private static function buildUserAgent(?string $suffix, callable $isAtHomeFn): string
    {
        $os = Platform::osToken(PHP_OS);
        $atHome = $isAtHomeFn() ? 'true' : 'false';
        $ua = sprintf('ApifyClient/%s (%s; PHP/%s); isAtHome/%s', Version::CLIENT_VERSION, $os, PHP_VERSION, $atHome);
        if ($suffix !== null && $suffix !== '') {
            $ua .= '; ' . $suffix;
        }
        return $ua;
    }
}
