# Apify PHP client documentation

The public API of the Apify PHP client by resource, with the parameters of each method and short snippets.
For configuration and error handling, see the [README](../README.md).

The snippets assume a client and imported types, for example `use Apify\Client\ApifyClient;`:

```php
$client = new ApifyClient('my-api-token');
```

### Namespaces

All classes are under the `Apify\Client\` root:

| Namespace | Contains | Examples |
|---|---|---|
| `Apify\Client\` | The entry point and version constants. | `ApifyClient`, `Version` |
| `Apify\Client\Model\` | Response models returned by the API. | `RequestQueueRequest`, `ActorEnvVar`, `Dataset`, `ActorRun`, `PaginationList` |
| `Apify\Client\Options\` | The `*Options` classes and enums. | `ActorListOptions`, `ActorStartOptions`, `RunListOptions`, `StorageListOptions`, `DatasetListItemsOptions`, `DownloadItemsFormat`, and the others in the [options reference](options.md) |
| `Apify\Client\Http\` | The transport, its adapters and the compressors. | `HttpClientInterface`, `NoRedirectHttpClientInterface`, `GuzzleHttpClient`, `Psr18HttpClient`, `HttpCompressorInterface`, `BrotliHttpCompressor`, `GzipHttpCompressor`, `ZstdHttpCompressor` |
| `Apify\Client\Exception\` | Exceptions thrown by the client. | `ApifyApiException` and its status-specific subclasses (`NotFoundException`, `RateLimitException`, ...), `TransportException` |

To add requests to a queue, import the model and the batch options:

```php
use Apify\Client\ApifyClient;
use Apify\Client\Model\RequestQueueRequest;
use Apify\Client\Options\BatchAddRequestsOptions;
```

`LogClient::stream()` and `RunClient::getStreamedLog()` return a PSR-7 `Psr\Http\Message\StreamInterface`.

A method that fetches one resource by ID returns `null` if it does not exist. A client obtained without an ID,
such as `RunClient::dataset()`, throws instead. See [error handling](../README.md#error-handling).

## ApifyClient methods

An accessor returns the client of a resource or collection. A resource accessor takes an ID, or a name where the
API allows it. A collection accessor takes no arguments and lists and creates. The methods of each client are on
the [resource pages](#resource-pages).

| Method | Returns | Notes |
|---|---|---|
| `actor(string $id): ActorClient` | Actor client | Single Actor, by ID or `username/name`. |
| `actors(): ActorCollectionClient` | Actor collection | List and create Actors. |
| `build(string $id): BuildClient` | Build client | Single Actor build. |
| `builds(): BuildCollectionClient` | Build collection | List builds across Actors. |
| `run(string $id): RunClient` | Run client | Single Actor run. |
| `runs(): RunCollectionClient` | Run collection | List runs across Actors. |
| `dataset(string $id): DatasetClient` | Dataset client | Single dataset, by ID or name. |
| `datasets(): DatasetCollectionClient` | Dataset collection | List and create datasets. |
| `keyValueStore(string $id): KeyValueStoreClient` | Key-value store client | Single store, by ID or name. |
| `keyValueStores(): KeyValueStoreCollectionClient` | Key-value store collection | List and create stores. |
| `requestQueue(string $id, ?RequestQueueClientOptions $options = null): RequestQueueClient` | Request queue client | Single queue, by ID or name; optional client options (`clientKey`, per-request `timeoutSecs`). |
| `requestQueues(): RequestQueueCollectionClient` | Request queue collection | List and create queues. |
| `task(string $id): TaskClient` | Task client | Single task. |
| `tasks(): TaskCollectionClient` | Task collection | List and create tasks. |
| `schedule(string $id): ScheduleClient` | Schedule client | Single schedule. |
| `schedules(): ScheduleCollectionClient` | Schedule collection | List and create schedules. |
| `webhook(string $id): WebhookClient` | Webhook client | Single webhook. |
| `webhooks(): WebhookCollectionClient` | Webhook collection | List and create webhooks. |
| `webhookDispatch(string $id): WebhookDispatchClient` | Webhook dispatch client | Single webhook dispatch. |
| `webhookDispatches(): WebhookDispatchCollectionClient` | Webhook dispatch collection | List webhook dispatches. |
| `store(): StoreCollectionClient` | Store collection | Browse the public Apify Store. |
| `log(string $buildOrRunId): LogClient` | Log client | Log for a build or run, by ID. |
| `me(): UserClient` | User client | The authenticated user (`users/me`). |
| `user(string $id): UserClient` | User client | A public user profile, by ID. |
| `setStatusMessage(string $message, bool $isTerminal = false): ActorRun` | Updated run | Set the current run's status message; see [Setting single-resource status](#setting-single-resource-status). |
| `getUserAgent(): string` | User-Agent string | The `User-Agent` the client sends. |
| `getApiBaseUrl(): string` | Base URL | The resolved API base URL (with `/v2`). |

## Models and unmodeled data (`toArray`)

A response model has getters for common fields, for example `$actor->getId()`, listed in the
[models reference](models.md). `toArray()` returns the whole object, including fields without a getter, such as
the `actions` of a `Schedule` or the private account details of `me()`:

```php
$schedule = $client->schedule('SCHEDULE_ID')->get();
$actions = $schedule?->toArray()['actions'] ?? null;
```

## Raw JSON values

Some methods return a decoded JSON value instead of a model, usually an associative array. `getInput()` returns
whatever JSON value was stored.

- Read: `me()->monthlyUsage(...)`, `me()->limits()`, `schedule($id)->getLog()`, `task($id)->getInput()`,
  `build($id)->getOpenApiDefinition()` and `dataset($id)->getStatistics()`. The request queue operations
  `listRequests`, `listAndLockHead`, `prolongRequestLock`, `unlockRequests` and `batchDeleteRequests` return
  [models](models.md).
- Write: the definition arguments of `update` and `create` accept any JSON-serializable value, usually an
  associative array.

## Options objects

Option objects take named constructor arguments. An unset field uses the API default:

```php
$options = new ActorListOptions(my: true, limit: 10);
$page = $client->actors()->list($options);
```

The [options reference](options.md) lists the fields of every option class.

## Common list options — `ListOptions`

Most `list` methods take `ListOptions`: `offset`, `limit` and `desc`.

```php
$builds = $client->builds()->list(new ListOptions(limit: 50, desc: true));
```

## Pagination — `PaginationList`

`list` methods return a `PaginationList`, which is iterable and countable and has `getTotal()`, `getOffset()`,
`getLimit()`, `getCount()`, `isDesc()` and `getItems()`. `listKeys` and `listHead` return their own page types.

For `DatasetClient::listItems()`, `getCount()` is the number of rows the API scanned, or the number of items
when that is larger. Filters such as `skipEmpty` and `clean` can leave it above the number of items. The next
page starts at `offset + min(count, limit)`.

The `iterate*()` helpers take a `$chunkSize`, the number of entries per API call. Without it, a call gets the
API's default page size, capped by the remaining `limit`. Dataset items have no default limit, so
`iterateItems()` without `$chunkSize` loads the whole dataset in one response.

```php
$page = $client->actors()->list(new ActorListOptions(limit: 5));
foreach ($page as $actor) {
    echo $actor->getName() . PHP_EOL;
}
```

## Setting single-resource status

`$client->setStatusMessage(string $message, bool $isTerminal = false)` sets the status message of the run in
the `ACTOR_RUN_ID` environment variable and returns the run. It throws outside a run.

## Resource pages

- [Actors, versions & environment variables](actors.md)
- [Builds](builds.md)
- [Runs](runs.md)
- [Storages (datasets, key-value stores, request queues)](storages.md)
- [Tasks](tasks.md)
- [Schedules](schedules.md)
- [Webhooks & dispatches](webhooks.md)
- [Store, users & logs](misc.md)
- [Models reference](models.md)
- [Options reference](options.md)
- [Runnable examples](examples.md)
