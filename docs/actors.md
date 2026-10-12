# Actors, versions & environment variables

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types.

## Actor collection — `$client->actors()`

- `list(?ActorListOptions $options = null): PaginationList` — list the account's Actors.
- `iterate(?ActorListOptions $options = null, ?int $chunkSize = null): iterable` — lazily iterate all matching Actors, paging on demand. The options' `limit` caps the total number yielded across all pages (unset = all); `$chunkSize` is the per-page size.
- `create(mixed $actor): Actor` — create a new Actor from a JSON-serializable definition.

```php
$page = $client->actors()->list(new ActorListOptions(my: true, limit: 10));

foreach ($client->actors()->iterate(new ActorListOptions(my: true), 100) as $actor) {
    echo $actor->getName() . PHP_EOL;
}

$actor = $client->actors()->create([
    'name' => 'my-actor',
    'isPublic' => false,
    'versions' => [[
        'versionNumber' => '0.0',
        'sourceType' => 'SOURCE_FILES',
        'buildTag' => 'latest',
        'sourceFiles' => [],
    ]],
]);
```

## A single Actor — `$client->actor($id)`

Addressed by ID or `username~name` (a `username/name` is accepted too).

- `get(): ?Actor`
- `update(mixed $newFields): Actor`
- `delete(): void`
- `start(mixed $input = null, ?ActorStartOptions $options = null): ActorRun` — start and return immediately.
- `call(mixed $input = null, ?ActorStartOptions $options = null, ?int $waitSecs = null): ActorRun` — start and wait.
- `validateInput(mixed $input = null, ?ValidateInputOptions $options = null): bool`

`$input` is a JSON-serializable array (the normal case), or a raw `string` of bytes sent exactly as
given — pair a raw string with a non-default `$options->contentType` (e.g. a scraper that takes a
plain-text URL list instead of JSON).

`$options->waitForResources` retries a start the API rejects for lack of account memory or a free
concurrent-run slot (`actor-memory-limit-exceeded`/`concurrent-runs-limit-exceeded`), every 10
seconds: `true` retries until the run starts, an `int` stops retrying after that many seconds and
throws the last error. In `call()`, time spent retrying does not count toward `$waitSecs`.
- `build(string $versionNumber, ?ActorBuildOptions $options = null): Build`
- `defaultBuild(?int $waitForFinish = null): BuildClient`
- `lastRun(?LastRunOptions $options = null): RunClient`
- `builds(): BuildCollectionClient`, `runs(): RunCollectionClient`
- `version(string $versionNumber): ActorVersionClient`, `versions(): ActorVersionCollectionClient`
- `webhooks(): NestedWebhookCollectionClient` — read-only.

```php
$run = $client->actor('apify/hello-world')->call(['name' => 'world'], new ActorStartOptions(memoryMbytes: 512), 120);
$isValid = $client->actor('apify/hello-world')->validateInput(['firstNumber' => 1]);
$lastSucceeded = $client->actor('apify/hello-world')->lastRun(new LastRunOptions(status: 'SUCCEEDED'))->get();
```

## Actor versions — `$client->actor($id)->versions()` / `->version($n)`

- Collection: `list(): PaginationList`, `iterate(): iterable`, `create(mixed $version): ActorVersion`.
- Single: `get(): ?ActorVersion`, `update(mixed $newFields): ActorVersion`, `delete(): void`.

The versions endpoint takes no filtering or pagination parameters — it always returns every version
in one response — so `list()`/`iterate()` take no options and `iterate()` makes a single request.

```php
$version = $client->actor('me~my-actor')->versions()->create([
    'versionNumber' => '0.1',
    'sourceType' => 'SOURCE_FILES',
    'sourceFiles' => [],
]);
```

## Environment variables — `->version($n)->envVars()` / `->envVar($name)`

- Collection: `list(): PaginationList`, `iterate(): iterable`, `create(ActorEnvVar $envVar): ActorEnvVar`.
- Single: `get(): ?ActorEnvVar`, `update(ActorEnvVar $envVar): ActorEnvVar`, `delete(): void`.

This endpoint likewise takes no filtering or pagination parameters, so `iterate()` makes a single
request, mirroring the reference client's parameterless iterator.

```php
$client->actor('me~my-actor')->version('0.0')->envVars()->create(new ActorEnvVar('API_KEY', 'secret', isSecret: true));
```
