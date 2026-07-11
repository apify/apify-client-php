# Apify PHP client documentation

> **Official, but experimental — AI-generated and AI-maintained.** This is an official Apify client,
> but it is experimental: it is generated and maintained by AI. Review the code before relying on it
> in production and report issues on the repository.

This directory documents the public API of the Apify PHP client, organized by resource. Each page
lists the available methods with their parameters and short, runnable snippets. For an overview,
configuration and error handling, see the [top-level README](../README.md).

All snippets assume a configured client and that the client types are imported from their namespaces
(e.g. `use Apify\Client\ApifyClient;`, `use Apify\Client\Options\ActorListOptions;`):

```php
$client = new ApifyClient('my-api-token');
```

### Namespaces

Every class is under the `Apify\Client\` PSR-4 root. Use these when writing `use` statements:

| Namespace | Contains | Examples |
|---|---|---|
| `Apify\Client\` | The entry point and version constants. | `ApifyClient`, `Version` |
| `Apify\Client\Model\` | Response models returned by the API. | `RequestQueueRequest`, `ActorEnvVar`, `Dataset`, `ActorRun`, `PaginationList` |
| `Apify\Client\Options\` | Option objects (all the `*Options` classes) **and** enums. | e.g. `ActorListOptions`, `ActorStartOptions`, `TaskStartOptions`, `RunListOptions`, `RunResurrectOptions`, `StorageListOptions`, `StoreListOptions`, `DatasetListItemsOptions`, `ListKeysOptions`, `GetRecordOptions`, `ListRequestsOptions`, `BatchAddRequestsOptions`, `PaginateRequestsOptions`, `LogOptions`, `DownloadItemsFormat` — see [options reference](options.md) for the full list |
| `Apify\Client\Http\` | The replaceable transport and its adapters. | `HttpClientInterface`, `GuzzleHttpClient`, `Psr18HttpClient` |
| `Apify\Client\Exception\` | Exceptions thrown by the client. | `ApifyApiException`, `TransportException` |

For example, to add requests to a queue you would import the model and (optionally) the batch options:

```php
use Apify\Client\ApifyClient;
use Apify\Client\Model\RequestQueueRequest;
use Apify\Client\Options\BatchAddRequestsOptions;
```

The streaming-log accessors (`LogClient::stream()` and `RunClient::getStreamedLog()`) return the
PSR-7 `Psr\Http\Message\StreamInterface` (from the `psr/http-message` package), not an
`Apify\Client\` type — import it as `use Psr\Http\Message\StreamInterface;`.

Methods that fetch a single resource return `null` when the resource does not exist, rather than
throwing. API failures are thrown as `ApifyApiException` (see [error handling](../README.md#error-handling)).

## Models and unmodeled data (`toArray`)

Response models expose the commonly-used fields as typed getters (e.g. `$actor->getId()`). The
[models reference](models.md) lists every model and its getters. The API returns more fields than are
modelled; every model also exposes `toArray()`, which returns the full raw object, so nothing the API
returns is lost. For example a `Schedule`'s `actions`/`isExclusive`, or the private account details
of `me()`, are available via `toArray()`:

```php
$schedule = $client->schedule('SCHEDULE_ID')->get();
$actions = $schedule?->toArray()['actions'] ?? null;
```

## Raw JSON values

A few methods return data whose shape is not modelled and is instead returned as a decoded
JSON value — typically an associative array, though `getInput()` is typed `mixed` and returns
whatever JSON value was stored (or accept an arbitrary value serialized to JSON):

- Read: `me()->monthlyUsage(...)`, `me()->limits()`, `task($id)->getInput()`,
  `build($id)->getOpenApiDefinition()`, `dataset($id)->getStatistics()`, and the raw request-queue
  operations that return a response body (`listRequests`, `listAndLockHead`, `prolongRequestLock`,
  `unlockRequests`, `batchDeleteRequests`). Note that `deleteRequestLock` returns `void` (it releases
  a lock and has no meaningful body), so it is not in this list.
- Write: definition/`update`/`create` arguments accept any JSON-serializable value — typically an
  associative array.

## Options objects

Option objects use named constructor arguments; an unset field means "use the API default". Pass only
the arguments you need:

```php
$options = new ActorListOptions(my: true, limit: 10);
$page = $client->actors()->list($options);
```

The [options reference](options.md) lists every option class and all of its fields.

## Common list options — `ListOptions`

Most `list` methods (builds, runs, tasks, schedules, webhooks, Actor versions) take the shared
`ListOptions`, which carries the standard pagination/ordering controls: `offset`, `limit`, `desc`.

```php
$builds = $client->builds()->list(new ListOptions(limit: 50, desc: true));
```

## Pagination — `PaginationList`

`list` methods return a `PaginationList`, which is iterable and countable and exposes `getTotal()`,
`getOffset()`, `getLimit()`, `getCount()`, `isDesc()` and `getItems()`. Within-storage listers
(`listKeys`, `listHead`) return their own page/head containers instead.

```php
$page = $client->actors()->list(new ActorListOptions(limit: 5));
foreach ($page as $actor) {
    echo $actor->getName() . PHP_EOL;
}
```

## Setting single-resource status

`$client->setStatusMessage(string $message, bool $isTerminal = false)` updates the status message of
the current Actor run (identified by the `ACTOR_RUN_ID` environment variable); it only works from
inside a run and throws otherwise. Returns the updated run.

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
