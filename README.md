# Apify API client for PHP

> **Official, but experimental.** This client is generated and maintained by AI. Review the code before
> relying on it in production, and report issues on the repository.

A PHP client for the [Apify API](https://docs.apify.com/api/v2), modeled on the
[JavaScript client](https://github.com/apify/apify-client-js). Start from an `ApifyClient` and go on to
Actors, runs, datasets, key-value stores, request queues, tasks, schedules, webhooks, the Store, users, and logs.

## Requirements

- PHP 8.1 or newer with the `json`, `mbstring`, and `hash` extensions.

## Installation

```bash
composer require apify/apify-client
```

## Quick start

The snippets assume that the client types are imported, for example `use Apify\Client\ApifyClient;`.

```php
$client = new ApifyClient('my-api-token');

// Start an Actor and wait for it to finish. The last argument bounds the wait in seconds,
// null waits indefinitely.
$run = $client->actor('apify/hello-world')->call(null, null, null);

// getDefaultDatasetId() is ?string, so cast it for dataset(string $id).
$items = $client->dataset((string) $run->getDefaultDatasetId())->listItems();
echo 'Item count: ' . $items->getCount() . PHP_EOL;
```

The client does not read `APIFY_TOKEN`. Pass it yourself, for example `new ApifyClient(getenv('APIFY_TOKEN'))`.
You can create a token in [Apify Console → Settings → API & Integrations](https://console.apify.com/settings/integrations).
Apify Console shows a token only once, right after you create or rotate it, so store it somewhere safe.

## Configuration

The constructor takes named arguments:

```php
$configured = new ApifyClient(
    token: 'my-api-token',
    baseUrl: 'https://api.apify.com',
    maxRetries: 5,
    minDelayBetweenRetriesMillis: 1000,
    timeoutSecs: 120,
    userAgentSuffix: 'my-app/1.2.3',
);
```

| Argument | Default | Meaning |
|---|---|---|
| `token` | `null` | API token, sent as a Bearer token. |
| `baseUrl` | `https://api.apify.com` | API base URL, with or without `/v2`. |
| `publicBaseUrl` | `baseUrl` | Base URL of public resource URLs, with or without `/v2`. |
| `maxRetries` | `8` | Maximum retries of a failed request. |
| `minDelayBetweenRetriesMillis` | `500` | Minimum delay between retries, which grows exponentially. |
| `maxDelayBetweenRetriesMillis` | `timeoutSecs × 1000` | Maximum delay between retries. |
| `timeoutSecs` | `360` | Default timeout of the `long` tier and of `timeoutMaxSecs`. |
| `timeoutShortSecs` | `5` | Timeout of the `short` tier, see [Timeout tiers](#timeout-tiers). |
| `timeoutMediumSecs` | `30` | Timeout of the `medium` tier. |
| `timeoutLongSecs` | `timeoutSecs` | Timeout of the `long` tier. |
| `timeoutMaxSecs` | `timeoutSecs` | Cap on the timeout of any attempt, per-call overrides, and growth across retries included. |
| `compression` | `null` (brotli, else gzip) | Request body compression: `'brotli'`, `'gzip'`, `'zstd'`, or a custom `HttpCompressorInterface`, see [HTTP compression](#http-compression). |
| `userAgentSuffix` | `null` | Suffix appended to the `User-Agent` header. |
| `httpClient` | Guzzle | The transport, an `Apify\Client\Http\HttpClientInterface`. |

Requests are retried with exponential backoff and jitter on network errors, HTTP 429, and 5xx. Other 4xx
responses throw an `ApifyApiException` subclass for the status. A 404 on a single resource is not thrown:
`get()` returns `null` and `delete()` does nothing, see [Error handling](#error-handling).

### Timeout tiers

Each method has a timeout tier: `short` for metadata reads and writes such as `get()`, `update()` and
`delete()`, `medium` for listing, batch, and trigger calls, and `long` for downloads, uploads, and streaming. The
tiers last 5 seconds, 30 seconds, and `timeoutSecs` (360 by default). Set `timeoutShortSecs`,
`timeoutMediumSecs` or `timeoutLongSecs` to change one.

A call that asks the API to hold its response with `waitForFinish` (`RunClient::get()`, `BuildClient::get()`,
`ActorClient::defaultBuild()`, and the `waitForFinish` option of `ActorClient::start()`, `build()` and
`TaskClient::start()`) gets that hold on top of its tier, for at most 60 seconds.

Every request method also takes a trailing `$timeoutSecs` that overrides the tier for that call: seconds, a
tier name (`'short'`, `'medium'`, `'long'`), or `'noTimeout'`:

```php
$client->actor('my-actor')->get(timeoutSecs: 10); // 10 seconds
$client->dataset('my-dataset')->listItems(timeoutSecs: 'long'); // the 'long' tier
$client->requestQueue('my-queue')->unlockRequests(timeoutSecs: 'noTimeout'); // no timeout
```

`timeoutMaxSecs` caps every tier and override, so raise it for calls that need more than 360 seconds. A retry
doubles the timeout up to that cap. The `timeoutSecs` of a request queue client, set in
`requestQueue(id, options)`, caps the calls of that client too.

### HTTP compression

A request body of at least 1 KiB is compressed, unless its `Content-Type` is already compressed (`image/*`,
`audio/*`, `video/*`, common archive, office, and font formats) or the request has a `Content-Encoding` header.
By default the client uses brotli when the PECL `brotli` extension is loaded and gzip (the `zlib` extension)
otherwise. Compression is best-effort: without a codec, the body is sent as it is.

Pass `compression` to choose an algorithm, or a compressor to also set the quality:

```php
use Apify\Client\Http\BrotliHttpCompressor;
use Apify\Client\Http\GzipHttpCompressor;
use Apify\Client\Http\ZstdHttpCompressor;

$client = new ApifyClient(token: 'my-api-token', compression: 'gzip');

// Brotli: 0-11, default 6. Gzip: 1-9, default 6. Zstd: 1-19, default 3.
$client = new ApifyClient(token: 'my-api-token', compression: new BrotliHttpCompressor(quality: 11));
$client = new ApifyClient(token: 'my-api-token', compression: new GzipHttpCompressor(quality: 1));
$client = new ApifyClient(token: 'my-api-token', compression: new ZstdHttpCompressor(quality: 19));
```

Brotli and zstd need their PECL extensions, and gzip needs `zlib`. Choosing a codec without its extension throws
`RuntimeException` when the client is created. A custom algorithm is an
`Apify\Client\Http\HttpCompressorInterface` implementation: `contentEncoding(): string` and
`compress(string $body): ?string`, which returns `null` to send the body as it is.

### Replaceable HTTP transport

The transport is an `Apify\Client\Http\HttpClientInterface`. The default is `GuzzleHttpClient`. Wrap any
[PSR-18](https://www.php-fig.org/psr/psr-18/) client with `Psr18HttpClient`, or write your own:

```php
$client = new ApifyClient(token: 'my-api-token', httpClient: new GuzzleHttpClient());

// PSR-18 has no per-request timeout, so configure it on the wrapped client.
$psr18 = new \GuzzleHttp\Client(['timeout' => 120]);
$client = new ApifyClient(token: 'my-api-token', httpClient: new Psr18HttpClient($psr18));
```

`ActorClient::server()->request()` needs a transport that returns redirects as they are. It throws with
`Psr18HttpClient` and any transport that does not implement `NoRedirectHttpClientInterface`. See
[Server Actors](docs/actors.md).

## Error handling

A method that fetches one resource by ID returns `null` if it does not exist, and `delete()` does nothing for a
missing resource. Other API failures throw `Apify\Client\Exception\ApifyApiException` or the subclass for the
status: `InvalidRequestException` (400), `UnauthorizedException` (401), `ForbiddenException` (403),
`NotFoundException` (404), `ConflictException` (409), `RateLimitException` (429), and `ServerException` (5xx).
All extend `ApifyApiException`.

```php
try {
    $client->actor('does/not-exist')->update(['title' => 'x']);
} catch (NotFoundException $e) {
    // The Actor does not exist, or the token cannot see it.
} catch (ApifyApiException $e) {
    echo $e->getStatusCode() . ' ' . $e->getType() . ': ' . $e->getApiMessage() . PHP_EOL;
}
```

A 404 resolves to `null` only if it is certain which resource is missing. A client obtained without an ID,
such as `RunClient::dataset()`, `keyValueStore()`, `requestQueue()`, `log()`, and `BuildClient::log()`, throws
instead, since the run or build may be the missing one:

```php
try {
    $client->run('missing-run-id')->dataset()->get();
} catch (NotFoundException $e) {
    // The run or its default dataset does not exist.
}
```

`DatasetClient::getStatistics()`, `TaskClient::getInput()`, `ScheduleClient::getLog()` and
`BuildClient::getOpenApiDefinition()` also always throw on a 404, since only a missing parent can cause it.
Lookups by key, such as `KeyValueStoreClient::getRecord()` and `RequestQueueClient::getRequest()`, still
return `null`.

`ApifyApiException` extends `RuntimeException` and has these accessors:

| Accessor | Returns |
|---|---|
| `getStatusCode(): int` | HTTP status code. |
| `getType(): ?string` | API error type, for example `"record-not-found"`. |
| `getApiMessage(): string` | API error message, without the status and type prefix. |
| `getMessage(): string` | Formatted message: `apify API error (status …, type …): …`. |
| `getAttempt(): int` | Number of the attempt that failed, starting at 1. |
| `getHttpMethod(): string` | HTTP method of the call. |
| `getPath(): string` | Endpoint path, without the origin. |
| `getData(): ?array` | Structured error data from the API, if any. |

A network failure or timeout is retried. When the retries run out, it throws
`Apify\Client\Exception\TransportException` (a `RuntimeException`, with `isTimeout()`). So `ApifyApiException`
means the server answered with an error, and `TransportException` means no usable response arrived.

A success response whose body is not valid JSON, for an endpoint that returns JSON, is not retried and throws
`Apify\Client\Exception\InvalidResponseBodyException`. An error response with an unparsable body, such as an
HTML 502 page, throws the `ApifyApiException` subclass for its status with the raw body in the message.

`ApifyApiException` and `InvalidResponseBodyException` extend `Apify\Client\Exception\ApifyClientException`,
so one `catch` handles both:

```php
try {
    $client->actor('my-actor')->update(['title' => 'x']);
} catch (ApifyClientException $e) {
    // An API error response, or a body that could not be parsed.
}
```

`\InvalidArgumentException` and `TransportException` do not extend it.

## Versioning

- `Apify\Client\Version::CLIENT_VERSION` is the semantic version of this library.
- `Apify\Client\Version::API_SPEC_VERSION` is the version of the Apify OpenAPI specification it is based on.

```php
echo Version::CLIENT_VERSION . ' / ' . Version::API_SPEC_VERSION . PHP_EOL;
```

## Documentation

See [`docs/`](docs/README.md), organized by resource, with runnable [examples](docs/examples.md).

## License

[Apache-2.0](LICENSE).
