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
- `call(mixed $input = null, ?ActorStartOptions $options = null, ?int $waitSecs = null, int|float|string|null $timeoutSecs = null): ActorRun` — start and wait. `$timeoutSecs` is the timeout of the start request and of every poll (default `'noTimeout'`).
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
- `server(): ActorServerClient` — the [server interface](#server-actors--client-actorid-server) of a Server Actor. Every call returns the same client.

```php
$run = $client->actor('apify/hello-world')->call(['name' => 'world'], new ActorStartOptions(memoryMbytes: 512), 120);
$isValid = $client->actor('apify/hello-world')->validateInput(['firstNumber' => 1]);
$lastSucceeded = $client->actor('apify/hello-world')->lastRun(new LastRunOptions(status: 'SUCCEEDED'))->get();
```

## Server Actors — `$client->actor($id)->server()`

A Server Actor (formerly Actor Standby) runs a web server that is called over HTTP without starting a run for
each call. `ActorServerClient` sends requests to that server and reads its URL and OpenAPI specification.

- `request(string $path = '/', ?ActorServerRequestOptions $options = null, int|float|string|null $timeoutSecs = null): ActorServerResponse` — sends an HTTP request to the Actor's server. The timeout defaults to the `long` tier, which covers the cold start of an idle Actor.
- `getUrl(int|float|string|null $timeoutSecs = null): string` — the server URL (the Actor's `standbyUrl`). It is fetched once per client, by `getUrl()` or `getOpenApiSpec()`.
- `getOpenApiSpec(int|float|string|null $timeoutSecs = null): ?array` — the web server schema from the build that serves the server (`actorStandby.build`, or `latest`), or `null` if the Actor's definition has none.

`request()` resolves `$path` against the server URL, so neither the request nor the token can reach another host. The
token goes in the `X-Apify-Authorization` header, which the platform removes before the request reaches the Actor, so
the headers you pass, `Authorization` included, reach the Actor as they are. Pass the query in the `params` option.

It returns any response of the server, error statuses included. Only a rate limit (429) and Service Unavailable (503)
are retried, with the client's retry settings, and the last response is returned once the retries run out. A transport
error, a timeout included, throws `TransportException` without a retry, since the Actor may already have handled the
request. A redirect is returned as it is, since following it would send the token to a host the Actor's code picked. A
request body is sent uncompressed unless the `compression` option is set.

The default `GuzzleHttpClient` can return a redirect as it is. A custom [`HttpClientInterface`](../README.md) must also
implement `NoRedirectHttpClientInterface` to be used for `request()`, which otherwise throws `ApifyClientException`
before it sends anything. `Psr18HttpClient` does not implement it, since a PSR-18 client decides on its own whether to
follow redirects.

`ActorServerResponse` offers `getStatusCode()`, `isSuccessful()`, `getHeaders()`, `getHeaderLine($name)`, `getBody()`
(the raw bytes), `getBodyStream()` (a PSR-7 stream, live with the `stream` option), `getData()` (the body parsed by its
content type: JSON is decoded, anything else is returned as a string) and `getResponse()` (the PSR-7 response).

```php
$server = $client->actor('username/actor-name')->server();

$response = $server->request('/search', new ActorServerRequestOptions(params: ['q' => 'apify']));
if ($response->isSuccessful()) {
    print_r($response->getData());
}

$url = $server->getUrl();
$spec = $server->getOpenApiSpec();
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

A `SOURCE_FILES` or `TARBALL` version can set `actorPath`, the Actor's directory inside the uploaded source, to push
an Actor from an upload of a whole monorepo. It is a path relative to the upload root, with `/` separators and no
leading, trailing or repeated `/`, and no `.` or `..` segment (1 to 255 characters). The API rejects it for a
`GIT_REPO` or `GITHUB_GIST` version. An `update()` that sends `sourceType`, `sourceFiles` or `tarballUrl` without
`actorPath` removes the stored path, so send it again with every upload; `'actorPath' => null` removes it too, and an
update of other fields keeps it. `ActorVersion::getActorPath()` reads it back.

```php
$version = $client->actor('me~my-actor')->versions()->create([
    'versionNumber' => '0.2',
    'sourceType' => 'SOURCE_FILES',
    'sourceFiles' => [],
    'actorPath' => 'actors/typescript-actor',
]);
$client->actor('me~my-actor')->version('0.2')->update([
    'sourceType' => 'SOURCE_FILES',
    'sourceFiles' => [],
    'actorPath' => 'actors/typescript-actor',
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
