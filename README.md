# Apify API client for PHP

> **Official, but experimental — AI-generated and AI-maintained.** This is an official Apify client,
> but it is experimental: it is generated and maintained by AI. Review the code before relying on it
> in production and report issues on the repository.

A resource-oriented PHP client for the [Apify API](https://docs.apify.com/api/v2), mirroring the
official [JavaScript](https://github.com/apify/apify-client-js) reference client: start from an
`ApifyClient`, then drill down into resources (Actors, runs, datasets, key-value stores, request
queues, tasks, schedules, webhooks, the store, users and logs).

## Requirements

- PHP 8.1 or newer, with the `json`, `mbstring` and `hash` extensions.

## Installation

```bash
composer require apify/apify-client
```

## Quick start

All snippets assume the client types are imported from their namespaces, e.g.
`use Apify\Client\ApifyClient;`.

```php
$client = new ApifyClient('my-api-token');

// Start an Actor and wait for it to finish. The last argument is the wait budget in seconds;
// pass a value (e.g. 120) to bound the wait, or null to wait indefinitely (as here).
$run = $client->actor('apify/hello-world')->call(null, null, null);

// Read items from the run's default dataset. getDefaultDatasetId() is ?string, so cast it
// to satisfy dataset(string $id).
$items = $client->dataset((string) $run->getDefaultDatasetId())->listItems();
echo 'Item count: ' . $items->getCount() . PHP_EOL;
```

`new ApifyClient('my-api-token')` takes the token as an explicit argument — it does **not** read
`APIFY_TOKEN` (or any other environment variable) automatically. Read it yourself if you want that,
e.g. `new ApifyClient(getenv('APIFY_TOKEN'))`.

Get your API token from the
[Apify Console → Settings → API & Integrations](https://console.apify.com/settings/integrations).

## Configuration

The constructor accepts named arguments for non-default settings:

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
| `baseUrl` | `https://api.apify.com` | API base URL, with or without the trailing `/v2` version path — appended automatically when not already present. |
| `publicBaseUrl` | `baseUrl` | Base URL used when building public, shareable resource URLs. Like `baseUrl`, `/v2` is appended when absent. |
| `maxRetries` | `8` | Maximum retries for failed requests. |
| `minDelayBetweenRetriesMillis` | `500` | Minimum delay between retries (exponential backoff). |
| `maxDelayBetweenRetriesMillis` | `timeoutSecs × 1000` (360000) | Upper bound (milliseconds) on the growing inter-retry delay; defaults to the request timeout expressed in milliseconds. |
| `timeoutSecs` | `360` | Overall per-request timeout, and the default duration of every timeout tier below that is left unset. |
| `timeoutShortSecs` / `timeoutMediumSecs` / `timeoutLongSecs` | `timeoutSecs` | Duration (seconds) of the named timeout tier — see [Timeout tiers](#timeout-tiers). |
| `timeoutMaxSecs` | `timeoutSecs` | Upper bound on any single request's timeout, including a per-call override. |
| `compression` | `null` (brotli, falling back to gzip) | Request-body compression: `'brotli'`, `'gzip'`, or a custom `HttpCompressorInterface` — see [HTTP compression](#http-compression). |
| `userAgentSuffix` | `null` | Custom suffix appended to the `User-Agent` header. |
| `httpClient` | Guzzle | The replaceable transport (`Apify\Client\Http\HttpClientInterface`). |

Requests are retried on network errors, HTTP 429 (rate limit) and 5xx responses, with exponential
backoff and jitter. Other 4xx responses are thrown immediately as an `ApifyApiException` subclass
matching the status code, with one exception: a resource-not-found 404 on a single-resource fetch is
not thrown — `get()` returns `null` and `delete()` is treated as a successful no-op (see
[Error handling](#error-handling)).

### Timeout tiers

Every method that sends a request is assigned a timeout tier: `short` (metadata reads/writes, e.g.
`get()`/`update()`/`delete()`), `medium` (listing/batch/trigger calls), or `long`
(downloads/uploads/streaming). Each tier's duration defaults to the client's single `timeoutSecs`, so
a client constructed without the tier options behaves exactly as before they existed; set
`timeoutShortSecs`/`timeoutMediumSecs`/`timeoutLongSecs` to give a tier its own duration instead.

The `get()`/`update()`/`delete()` methods of every resource client additionally take an optional
trailing `$timeoutSecs`, overriding the tier for that one call — a number of seconds, a tier name
(`'short'`/`'medium'`/`'long'`), or `'noTimeout'` for no request timeout at all:

```php
$client->actor('my-actor')->get(timeoutSecs: 10); // this call only, 10 seconds
$client->actor('my-actor')->get(timeoutSecs: 'long'); // this call only, the 'long' tier's duration
```

`timeoutMaxSecs` caps every tier and every per-call override alike (defaulting to `timeoutSecs`), so
raise it whenever a call legitimately needs longer than the default 360 seconds.

### HTTP compression

Request bodies above 1 KiB are compressed before being sent, unless their `Content-Type` already
carries its own compression (`image/*`, `audio/*`, `video/*`, common archive/office/font formats) or
the caller already set a `Content-Encoding` header. By default the client picks brotli when the
optional PECL `brotli` extension is loaded, falling back to gzip (PHP's standard `zlib` extension)
otherwise — compression is always best-effort, so a body is sent uncompressed rather than the request
failing when neither codec is available.

Pass `compression` to pick an algorithm explicitly, or an instance for a custom quality:

```php
use Apify\Client\Http\BrotliHttpCompressor;
use Apify\Client\Http\GzipHttpCompressor;

$client = new ApifyClient(token: 'my-api-token', compression: 'gzip');

// A custom quality (brotli: 0-11, default 6; gzip: 1-9, default 6).
$client = new ApifyClient(token: 'my-api-token', compression: new BrotliHttpCompressor(quality: 11));
$client = new ApifyClient(token: 'my-api-token', compression: new GzipHttpCompressor(quality: 1));
```

A custom algorithm is any `Apify\Client\Http\HttpCompressorInterface` implementation (a
`contentEncoding(): string` plus a `compress(string $body): ?string` that returns `null` to leave a
body uncompressed).

### Replaceable HTTP transport

The transport is the `Apify\Client\Http\HttpClientInterface`. The default is `GuzzleHttpClient`; you
can wrap any [PSR-18](https://www.php-fig.org/psr/psr-18/) client with `Psr18HttpClient`, or provide
your own implementation:

```php
// Use the default Guzzle transport explicitly.
$client = new ApifyClient(token: 'my-api-token', httpClient: new GuzzleHttpClient());

// Or wrap any PSR-18 client (configure its proxy/TLS/timeout on the wrapped client, since
// PSR-18 has no per-request timeout and Psr18HttpClient ignores the client's timeoutSecs).
$psr18 = new \GuzzleHttp\Client(['timeout' => 120]); // any Psr\Http\Message ClientInterface
$client = new ApifyClient(token: 'my-api-token', httpClient: new Psr18HttpClient($psr18));
```

## Error handling

Methods that fetch a single resource by ID return `null` when the resource does not exist (rather
than throwing), and `delete()` is a no-op for a resource that is already gone. Other API failures are
thrown as `Apify\Client\Exception\ApifyApiException`, or one of its subclasses matching the response's
HTTP status — `InvalidRequestException` (400), `UnauthorizedException` (401), `ForbiddenException`
(403), `NotFoundException` (404), `ConflictException` (409), `RateLimitException` (429), or
`ServerException` (any 5xx) — so a `catch` block can branch on `instanceof` instead of comparing
status codes or `getType()` strings. Any other status still throws a plain `ApifyApiException`, and
every subclass extends it, so an existing `instanceof ApifyApiException` check keeps matching all of
them:

```php
use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Exception\NotFoundException;

try {
    $client->actor('does/not-exist')->update(['title' => 'x']);
} catch (NotFoundException $e) {
    // The Actor doesn't exist, or the token can't see it.
} catch (ApifyApiException $e) {
    echo $e->getStatusCode() . ' ' . $e->getType() . ': ' . $e->getApiMessage() . PHP_EOL;
}
```

A 404 is swallowed (resolving to `null`/no-op) only when it can be pinned to the resource the call
addresses. A resource client obtained *without* an ID — `RunClient::dataset()`/`keyValueStore()`/
`requestQueue()`/`log()`, `BuildClient::log()` — throws instead, since the 404 there could mean either
the parent run/build or the sub-resource is gone:

```php
try {
    $client->run('missing-run-id')->dataset()->get();
} catch (NotFoundException $e) {
    // Either the run or its default dataset does not exist — the response cannot tell which.
}
```

The same rule applies to a handful of fixed sub-paths whose only way to 404 is their parent being
gone: `DatasetClient::getStatistics()`, `TaskClient::getInput()`, `ScheduleClient::getLog()`, and
`BuildClient::getOpenApiDefinition()` always throw rather than returning `null`. Lookups by key, such
as `KeyValueStoreClient::getRecord()`/`RequestQueueClient::getRequest()`, are unaffected and still
resolve to `null` for a missing record/request.

`ApifyApiException` extends `RuntimeException` and exposes:

| Accessor | Returns |
|---|---|
| `getStatusCode(): int` | HTTP status code of the error response. |
| `getType(): ?string` | Machine-readable API error type (e.g. `"record-not-found"`). |
| `getApiMessage(): string` | Raw API error message, without the status/type prefix. |
| `getMessage(): string` | Formatted message (`apify API error (status …, type …): …`), from `Throwable`. |
| `getAttempt(): int` | 1-based number of the request attempt that produced the error. |
| `getHttpMethod(): string` | HTTP method of the failed call (e.g. `"GET"`). |
| `getPath(): string` | Path of the API endpoint (URL excluding origin). |
| `getData(): ?array` | Additional structured error data provided by the API, if any. |

Transport-level failures (network errors, timeouts) are retried internally; only if every retry is
exhausted does the underlying error surface, as an `Apify\Client\Exception\TransportException`
(a `RuntimeException`; `isTimeout()` reports whether a request timed out). In short:
`ApifyApiException` means the server returned an error response (a 4xx/5xx with a body), whereas
`TransportException` means the request never produced a usable response (network failure or timeout)
after all retries. Requests are retried on network errors, HTTP 429 and 5xx.

## Versioning

- `Apify\Client\Version::CLIENT_VERSION` — the semantic version of this library.
- `Apify\Client\Version::API_SPEC_VERSION` — the Apify OpenAPI spec version this client was built
  against.

```php
echo Version::CLIENT_VERSION . ' / ' . Version::API_SPEC_VERSION . PHP_EOL;
```

## Documentation

Full documentation lives in [`docs/`](docs/README.md), organized by resource, with runnable
[examples](docs/examples.md).

## License

[Apache-2.0](LICENSE).
