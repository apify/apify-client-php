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

- Collection: `list(?ListOptions): PaginationList`, `iterate(?ListOptions $options = null, ?int $chunkSize = null): iterable`, `create(mixed $version): ActorVersion`.
- Single: `get(): ?ActorVersion`, `update(mixed $newFields): ActorVersion`, `delete(): void`.

```php
$version = $client->actor('me~my-actor')->versions()->create([
    'versionNumber' => '0.1',
    'sourceType' => 'SOURCE_FILES',
    'sourceFiles' => [],
]);
```

## Environment variables — `->version($n)->envVars()` / `->envVar($name)`

- Collection: `list(): PaginationList`, `iterate(?int $chunkSize = null): iterable`, `create(ActorEnvVar $envVar): ActorEnvVar`.
- Single: `get(): ?ActorEnvVar`, `update(ActorEnvVar $envVar): ActorEnvVar`, `delete(): void`.

`iterate()` on the environment-variable collection takes only the optional `$chunkSize` (per-page
size); the endpoint has no filters, mirroring the reference client's parameterless iterator.

```php
$client->actor('me~my-actor')->version('0.0')->envVars()->create(new ActorEnvVar('API_KEY', 'secret', isSecret: true));
```
