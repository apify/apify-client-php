# Runs

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types.

## Run collection — `$client->runs()`

- `list(?ListOptions $options = null, ?RunListOptions $filter = null): PaginationList`: list runs.
- `iterate(?ListOptions $options = null, ?RunListOptions $filter = null, ?int $chunkSize = null): iterable`: lazily yields the runs, with the filters applied to every page. The `limit` option caps the total (unset for all), `$chunkSize` is the page size.

```php
$page = $client->runs()->list(new ListOptions(limit: 10), new RunListOptions(status: ['SUCCEEDED']));

foreach ($client->runs()->iterate(new ListOptions(limit: 100), new RunListOptions(status: ['SUCCEEDED']), 50) as $run) {
    echo $run->getId() . PHP_EOL;
}
```

An Actor's or task's runs are available at `$client->actor($id)->runs()` / `$client->task($id)->runs()`.

## A single run — `$client->run($id)`

- `get(?int $waitForFinishSecs = null): ?ActorRun`: fetches the run. The API can hold the response until the run finishes, for at most 60 seconds.
- `update(mixed $newFields): ActorRun`
- `delete(): void`
- `abort(?bool $gracefully = null): ActorRun`: with `$gracefully` `true`, the run can finish its current request first. `false` and `null` (the default) abort at once.
- `metamorph(string $targetActorId, mixed $input = null, ?MetamorphOptions $options = null): ActorRun`: `$input` is as for `ActorClient::start()`.
- `reboot(): ActorRun`
- `resurrect(?RunResurrectOptions $options = null): ActorRun`
- `charge(RunChargeOptions $options): void`: for pay-per-event Actors.
- `waitForFinish(?int $waitSecs = null, int|float|string|null $timeoutSecs = null): ActorRun`: `$timeoutSecs` is the timeout of each poll request (default `'noTimeout'`).
- `dataset(): DatasetClient`, `keyValueStore(): KeyValueStoreClient`, `requestQueue(): RequestQueueClient`: a 404 from `get()` or `delete()` is thrown, since the client cannot tell a missing run from a missing storage.
- `iterateDatasetItems(?RunIterateDatasetItemsOptions $options = null, int|float|string|null $timeoutSecs = null): Generator`: lazily yields the items of the default dataset while the run is producing them, and returns when the run has finished and the dataset is drained. `$timeoutSecs` is the timeout of each request, polls included (default: the `long` tier). See [below](#reading-a-dataset-while-the-run-is-running).
- `log(): LogClient`, `getStreamedLog(): StreamInterface`: `log()->get()` throws on a 404 for the same reason.

```php
$run = $client->run('RUN_ID')->waitForFinish(120);
$client->run('RUN_ID')->charge(new RunChargeOptions(eventName: 'result', count: 3));
$items = $client->run('RUN_ID')->dataset()->listItems();
```

### Reading a dataset while the run is running

`iterateDatasetItems()` yields the items as the run pushes them. It polls the `itemCount` of the dataset and waits up
to `pollIntervalSecs` (default 5) for the run to finish between polls. It returns when the run has finished and the
remaining rows are read, which covers the few seconds that `itemCount` lags behind. A page ends at `itemCount`, so
offsets stay exact even when `clean`, `skipEmpty`, or `unwind` change the number of items. On a last-run client
(`actor($id)->lastRun()`), the iteration stays on the run its first request resolved to.

```php
$run = $client->actor('my-actor')->start();

foreach ($client->run($run->getId())->iterateDatasetItems(new RunIterateDatasetItemsOptions(chunkSize: 100)) as $item) {
    echo json_encode($item) . PHP_EOL;
}
```

### `waitForFinish` — two distinct meanings

The name has two meanings:

- **`waitForFinish(?int $waitSecs = null)`** is the client-side method of runs and builds. It polls until the run or
  build is terminal, with repeated server-side waits. `$waitSecs` is the total budget in seconds, not capped, and
  `null` waits indefinitely. A 404 right after creation, which is replica lag, is tolerated for up to 3 seconds.
  If it lasts longer, the wait throws `NotFoundException`.
- **The server-side `waitForFinish` parameter** is in `get(?int $waitForFinishSecs = null)`, `defaultBuild()` and
  the `waitForFinish` field of the `*Options`, such as `ActorStartOptions`. It is one wait of the API, capped at
  60 seconds, and the client clamps larger values. The timeout of the request is that wait on top of the
  [timeout tier](../README.md#timeout-tiers) of the method.
