# Runs

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types.

## Run collection — `$client->runs()`

- `list(?ListOptions $options, ?RunListOptions $filter): PaginationList` — list runs.

```php
$page = $client->runs()->list(new ListOptions(limit: 10), new RunListOptions(status: ['SUCCEEDED']));
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
