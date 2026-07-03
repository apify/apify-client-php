# Builds

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types.

## Build collection — `$client->builds()`

- `list(?ListOptions $options): PaginationList` — list the account's builds.

```php
$page = $client->builds()->list(new ListOptions(limit: 20, desc: true));
```

An Actor's builds are available at `$client->actor($id)->builds()`.

## A single build — `$client->build($id)`

- `get(?int $waitForFinishSecs = null): ?Build` — fetch, optionally waiting server-side (max 60s).
- `abort(): Build`
- `delete(): void`
- `waitForFinish(?int $waitSecs = null): Build` — poll until terminal (`null` waits indefinitely).
- `getOpenApiDefinition(): ?array` — the build's generated OpenAPI document, or `null`.
- `log(): LogClient`

```php
$build = $client->actor('me~my-actor')->build('0.0', new ActorBuildOptions(tag: 'latest'));
$finished = $client->build($build->getId())->waitForFinish(300);
echo $finished->getStatus() . PHP_EOL;
$log = $client->build($build->getId())->log()->get();
```
