# Builds

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types.

## Build collection — `$client->builds()`

- `list(?ListOptions $options = null): PaginationList`: list the account's builds.
- `iterate(?ListOptions $options = null, ?int $chunkSize = null): iterable`: lazily yields the builds. The `limit` option caps the total (unset for all), `$chunkSize` is the page size.

```php
$page = $client->builds()->list(new ListOptions(limit: 20, desc: true));

foreach ($client->builds()->iterate(new ListOptions(desc: true), 50) as $build) {
    echo $build->getId() . PHP_EOL;
}
```

An Actor's builds are available at `$client->actor($id)->builds()`.

## A single build — `$client->build($id)`

- `get(?int $waitForFinishSecs = null): ?Build`: fetches the build. The API can hold the response until the build finishes, for at most 60 seconds.
- `abort(): Build`
- `delete(): void`
- `waitForFinish(?int $waitSecs = null, int|float|string|null $timeoutSecs = null): Build`: polls until the build is terminal (`null` waits indefinitely). `$timeoutSecs` is the timeout of each poll (default `'noTimeout'`).
- `getOpenApiDefinition(): ?array`: the generated OpenAPI document of the build. Throws if the build does not exist.
- `log(): LogClient`: a 404 from `get()` is thrown, since the client cannot tell a missing build from a missing log.

```php
$build = $client->actor('me~my-actor')->build('0.0', new ActorBuildOptions(tag: 'latest'));
$finished = $client->build($build->getId())->waitForFinish(300);
echo $finished->getStatus() . PHP_EOL;
$log = $client->build($build->getId())->log()->get();
```
