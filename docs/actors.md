# Actors, versions & environment variables

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types.

## Actor collection — `$client->actors()`

- `list(?ActorListOptions $options = null): PaginationList`: list the account's Actors.
- `iterate(?ActorListOptions $options = null, ?int $chunkSize = null): iterable`: lazily yields the Actors. The `limit` option caps the total (unset for all), `$chunkSize` is the page size.
- `create(mixed $actor): Actor`: create an Actor from a JSON-serializable definition.

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
- `start(mixed $input = null, ?ActorStartOptions $options = null): ActorRun`: start and return immediately.
- `call(mixed $input = null, ?ActorStartOptions $options = null, ?int $waitSecs = null, int|float|string|null $timeoutSecs = null): ActorRun`: start and wait. `$timeoutSecs` is the timeout of the start request and of every poll (default `'noTimeout'`).
- `validateInput(mixed $input = null, ?ValidateInputOptions $options = null): bool`

`$input` is a JSON-serializable array, or a `string` sent as it is. Set `$options->contentType` for a string, for
example a plain-text URL list.

`$options->waitForResources` retries a start that the API rejects for lack of account memory or a free run slot
(`actor-memory-limit-exceeded`, `concurrent-runs-limit-exceeded`) every 10 seconds. `true` retries until the run
starts, an `int` for that many seconds, and then throws the last error. In `call()`, the retries do not count
toward `$waitSecs`.

- `build(string $versionNumber, ?ActorBuildOptions $options = null): Build`
- `defaultBuild(?int $waitForFinish = null): BuildClient`
- `lastRun(?LastRunOptions $options = null): RunClient`
- `builds(): BuildCollectionClient`, `runs(): RunCollectionClient`
- `version(string $versionNumber): ActorVersionClient`, `versions(): ActorVersionCollectionClient`
- `webhooks(): NestedWebhookCollectionClient`: read-only.
- `server(): ActorServerClient`: the [server interface](#server-actors--client-actorid-server) of a Server Actor. Every call returns the same client.

```php
$run = $client->actor('apify/hello-world')->call(['name' => 'world'], new ActorStartOptions(memoryMbytes: 512), 120);
$isValid = $client->actor('apify/hello-world')->validateInput(['firstNumber' => 1]);
$lastSucceeded = $client->actor('apify/hello-world')->lastRun(new LastRunOptions(status: 'SUCCEEDED'))->get();
```

## Server Actors — `$client->actor($id)->server()`

A Server Actor runs a web server that answers HTTP requests without a run for each request.
`ActorServerClient` sends requests to that server and reads its URL and OpenAPI specification.

- `request(string $path = '/', ?ActorServerRequestOptions $options = null, int|float|string|null $timeoutSecs = null): ActorServerResponse`: sends an HTTP request to the server. The timeout defaults to the `long` tier, which covers the start of an idle Actor.
- `getUrl(int|float|string|null $timeoutSecs = null): string`: the server URL, the `standbyUrl` of the Actor. It is fetched once per client.
- `getOpenApiSpec(int|float|string|null $timeoutSecs = null): ?array`: the web server schema of the build that serves the server (`actorStandby.build`, else `latest`), or `null` if the Actor has none.

`request()` resolves `$path` against the server URL, so the request and the token cannot reach another host. Pass
the query in the `params` option. The token goes in the `X-Apify-Authorization` header, which the platform removes,
so the headers you pass, `Authorization` included, reach the Actor as they are.

Any response of the server is returned, error statuses included. A 429 or 503 is retried with the client's retry
settings, and the last response is returned when they run out. A transport error, a timeout included, throws
`TransportException` without a retry, since the Actor may have handled the request. A redirect is returned as it
is, so the token never follows it to another host. The body is sent uncompressed unless the `compression` option
is set.

A custom [`HttpClientInterface`](../README.md) must also implement `NoRedirectHttpClientInterface` for `request()`,
which otherwise throws `ApifyClientException` before sending anything. `GuzzleHttpClient` implements it,
`Psr18HttpClient` does not.

`ActorServerResponse` has `getStatusCode()`, `isSuccessful()`, `getHeaders()`, `getHeaderLine($name)`, `getBody()`
(the raw bytes), `getBodyStream()` (a PSR-7 stream, live with the `stream` option), `getData()` (JSON decoded, any
other body a string) and `getResponse()` (the PSR-7 response).

A multi-tenant Server Actor shares the runs of its developer among its users and tells their requests apart by the
`X-Actor-Request-ID` header that the platform adds to each request. When such an Actor calls another Server Actor
while it handles a request, pass that header in `parentRequestId`. The client sends it as the same header, so the
called Actor charges at the pricing tier of the incoming request. Use a client created with the `APIFY_TOKEN` of the
run.

```php
$server = $client->actor('username/actor-name')->server();

$response = $server->request('/search', new ActorServerRequestOptions(params: ['q' => 'apify']));
if ($response->isSuccessful()) {
    print_r($response->getData());
}

$url = $server->getUrl();
$spec = $server->getOpenApiSpec();

// Inside a multi-tenant Server Actor that handles a request with the header $incomingRequestId.
$incomingRequestId = 'REQUEST_ID_HEADER_VALUE';
$response = $server->request('/search', new ActorServerRequestOptions(
    params: ['q' => 'apify'],
    parentRequestId: $incomingRequestId,
));
```

## Actor versions — `$client->actor($id)->versions()` / `->version($n)`

- Collection: `list(): PaginationList`, `iterate(): iterable`, `create(mixed $version): ActorVersion`.
- Single: `get(): ?ActorVersion`, `update(mixed $newFields): ActorVersion`, `delete(): void`.

The endpoint returns all versions in one response, so `list()` and `iterate()` take no options and `iterate()`
makes one request.

```php
$version = $client->actor('me~my-actor')->versions()->create([
    'versionNumber' => '0.1',
    'sourceType' => 'SOURCE_FILES',
    'sourceFiles' => [],
]);
```

A `SOURCE_FILES` or `TARBALL` version can set `actorPath`, the directory of the Actor inside the uploaded source,
to push an Actor from an upload of a monorepo. It is relative to the upload root, 1 to 255 characters, with `/`
separators, no leading, trailing, or repeated `/`, and no `.` or `..` segment. The API rejects it for a `GIT_REPO`
or `GITHUB_GIST` version. An `update()` that sends `sourceType`, `sourceFiles`, or `tarballUrl` without `actorPath`
removes the stored path, so send it with every upload. `'actorPath' => null` removes it too, and an update of
other fields keeps it. `ActorVersion::getActorPath()` reads it.

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

The endpoint takes no filter or pagination parameters, so `iterate()` makes one request.

```php
$client->actor('me~my-actor')->version('0.0')->envVars()->create(new ActorEnvVar('API_KEY', 'secret', isSecret: true));
```
