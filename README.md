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

// Read items from the run's default dataset.
$items = $client->dataset($run->getDefaultDatasetId())->listItems();
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
| `baseUrl` | `https://api.apify.com` | API base URL; the `/v2` suffix is appended automatically. |
| `publicBaseUrl` | `baseUrl` | Base URL used when building public, shareable resource URLs. |
| `maxRetries` | `8` | Maximum retries for failed requests. |
| `minDelayBetweenRetriesMillis` | `500` | Minimum delay between retries (exponential backoff). |
| `maxDelayBetweenRetriesMillis` | `timeoutSecs × 1000` (360000) | Upper bound (milliseconds) on the growing inter-retry delay; defaults to the request timeout expressed in milliseconds. |
| `timeoutSecs` | `360` | Overall per-request timeout. |
| `userAgentSuffix` | `null` | Custom suffix appended to the `User-Agent` header. |
| `httpClient` | Guzzle | The replaceable transport (`Apify\Client\Http\HttpClientInterface`). |

Requests are retried on network errors, HTTP 429 (rate limit) and 5xx responses, with exponential
backoff and jitter. Other 4xx responses are thrown immediately as `ApifyApiException`, with one
exception: a resource-not-found 404 (the API's `record-not-found` / `record-or-token-not-found`
error type) on a single-resource fetch is not thrown — `get()` returns `null` and `delete()` is
treated as a successful no-op (see [Error handling](#error-handling)).

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

Methods that fetch a single resource return `null` when the resource does not exist (rather than
throwing). Other API failures are thrown as `Apify\Client\Exception\ApifyApiException`:

```php
try {
    $client->actor('does/not-exist')->update(['title' => 'x']);
} catch (ApifyApiException $e) {
    echo $e->getStatusCode() . ' ' . $e->getType() . ': ' . $e->getApiMessage() . PHP_EOL;
}
```

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
