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
- `abort(?bool $gracefully = null): ActorRun`
- `metamorph(string $targetActorId, mixed $input = null, ?MetamorphOptions $options = null): ActorRun`
- `reboot(): ActorRun`
- `resurrect(?RunResurrectOptions $options = null): ActorRun`
- `charge(RunChargeOptions $options): void` — for pay-per-event Actors.
- `waitForFinish(?int $waitSecs = null): ActorRun`
- `dataset(): DatasetClient`, `keyValueStore(): KeyValueStoreClient`, `requestQueue(): RequestQueueClient`
- `log(): LogClient`, `getStreamedLog(): StreamInterface`

```php
$run = $client->run('RUN_ID')->waitForFinish(120);
$client->run('RUN_ID')->charge(new RunChargeOptions(eventName: 'result', count: 3));
$items = $client->run('RUN_ID')->dataset()->listItems();
```

### `waitForFinish` — two distinct meanings

`waitForFinish` appears in two different roles; do not confuse them:

- **`waitForFinish(?int $waitSecs = null)`** — the client-side helper method (on runs and builds). It
  polls until the run/build reaches a terminal state, transparently issuing repeated server-side
  waits. `$waitSecs` is the total budget in seconds and is **not** capped; `null` waits indefinitely.
  For instance, `waitForFinish(300)` waits up to five minutes.
- **The server-side `waitForFinish` parameter** — `get(?int $waitForFinishSecs = null)` (and
  `defaultBuild()`) and the `waitForFinish` field on `*Options` (e.g. `ActorStartOptions`). This is a
  single API-side wait and the server caps it at 60 seconds, so the client clamps larger values.
