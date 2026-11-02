# Runs

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types.

## Run collection — `$client->runs()`

- `list(?ListOptions $options = null, ?RunListOptions $filter = null): PaginationList` — list runs.
- `iterate(?ListOptions $options = null, ?RunListOptions $filter = null, ?int $chunkSize = null): iterable` — lazily iterate all runs, applying the filters to every page. The options' `limit` caps the total number yielded across all pages (unset = all); `$chunkSize` is the per-page size.

```php
$page = $client->runs()->list(new ListOptions(limit: 10), new RunListOptions(status: ['SUCCEEDED']));

foreach ($client->runs()->iterate(new ListOptions(limit: 100), new RunListOptions(status: ['SUCCEEDED']), 50) as $run) {
    echo $run->getId() . PHP_EOL;
}
```

An Actor's or task's runs are available at `$client->actor($id)->runs()` / `$client->task($id)->runs()`.

## A single run — `$client->run($id)`

- `get(?int $waitForFinishSecs = null): ?ActorRun` — fetch, optionally waiting server-side (max 60s).
- `update(mixed $newFields): ActorRun`
- `delete(): void`
- `abort(?bool $gracefully = null): ActorRun` — aborts the run; with `$gracefully` `true` the run is signalled so it can finish its current request before terminating, `false` aborts immediately, and `null` (the default) lets the server apply its default (immediate abort).
- `metamorph(string $targetActorId, mixed $input = null, ?MetamorphOptions $options = null): ActorRun` — `$input` accepts the same shapes as `ActorClient::start()`'s (a JSON-serializable array, or a raw string of bytes with a matching `$options->contentType`).
- `reboot(): ActorRun`
- `resurrect(?RunResurrectOptions $options = null): ActorRun`
- `charge(RunChargeOptions $options): void` — for pay-per-event Actors.
- `waitForFinish(?int $waitSecs = null, int|float|string|null $timeoutSecs = null): ActorRun` — `$timeoutSecs` is the timeout of each poll request (default `'noTimeout'`).
- `dataset(): DatasetClient`, `keyValueStore(): KeyValueStoreClient`, `requestQueue(): RequestQueueClient`
  — `get()`/`delete()` on these throw instead of resolving to `null`/no-op on a 404, since there is no
  ID to pin the 404 to the run or the sub-resource.
- `iterateDatasetItems(?RunIterateDatasetItemsOptions $options = null, int|float|string|null $timeoutSecs = null): Generator` — lazily iterates the items of the run's default dataset *while the run is still producing them*, and returns once the run has finished and the dataset is drained. `$timeoutSecs` is the timeout of each request (default `'long'` tier), also used for the polls between pages. See [below](#reading-a-dataset-while-the-run-is-running).
- `log(): LogClient`, `getStreamedLog(): StreamInterface` — `log()->get()` throws on a 404 for the same reason.

```php
$run = $client->run('RUN_ID')->waitForFinish(120);
$client->run('RUN_ID')->charge(new RunChargeOptions(eventName: 'result', count: 3));
$items = $client->run('RUN_ID')->dataset()->listItems();
```

### Reading a dataset while the run is running

`iterateDatasetItems()` yields the run's dataset items as the run pushes them, polling the dataset's
`itemCount` and waiting up to `pollIntervalSecs` (default 5) for the run to finish between polls. It
returns after the run has finished and the remaining rows are read, which also covers the few seconds
the dataset's `itemCount` lags behind the pushed items. Each page is requested with a `limit` that ends at
`itemCount`, so offsets stay exact even when `clean`, `skipEmpty` or `unwind` change how many items a page
returns. On a last-run client (`actor($id)->lastRun()`), the iteration sticks to the run its first request
resolved to.

```php
$run = $client->actor('my-actor')->start();

foreach ($client->run($run->getId())->iterateDatasetItems(new RunIterateDatasetItemsOptions(chunkSize: 100)) as $item) {
    echo json_encode($item) . PHP_EOL;
}
```

### `waitForFinish` — two distinct meanings

`waitForFinish` appears in two different roles; do not confuse them:

- **`waitForFinish(?int $waitSecs = null)`** — the client-side helper method (on runs and builds). It
  polls until the run/build reaches a terminal state, transparently issuing repeated server-side
  waits. `$waitSecs` is the total budget in seconds and is **not** capped; `null` waits indefinitely.
  For instance, `waitForFinish(300)` waits up to five minutes. A 404 (replica lag right after the run
  or build is created) is tolerated for up to 3 seconds; if it keeps 404ing past that, the wait gives
  up and throws a `NotFoundException` instead of polling for the rest of `$waitSecs`.
- **The server-side `waitForFinish` parameter** — `get(?int $waitForFinishSecs = null)` (and
  `defaultBuild()`) and the `waitForFinish` field on `*Options` (e.g. `ActorStartOptions`). This is a
  single API-side wait and the server caps it at 60 seconds, so the client clamps larger values. The
  request's timeout is that wait, at most 60 seconds, on top of the method's [timeout tier](../README.md#timeout-tiers).
