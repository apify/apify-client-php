# Tasks

Tasks are pre-configured Actor runs with stored input. Snippets assume
`$client = new ApifyClient('my-api-token');` and imported types.

## Task collection — `$client->tasks()`

- `list(?ListOptions $options): PaginationList`
- `create(mixed $task): Task`

```php
$task = $client->tasks()->create([
    'actId' => 'apify/hello-world',
    'name' => 'my-task',
    'input' => ['message' => 'hello'],
]);
```

## A single task — `$client->task($id)`

- `get(): ?Task`, `update(mixed $newFields): Task`, `delete(): void`
- `start(mixed $input = null, ?TaskStartOptions $options = null): ActorRun`
- `call(mixed $input = null, ?TaskStartOptions $options = null, ?int $waitSecs = null): ActorRun`
- `getInput(): mixed`, `updateInput(mixed $input): mixed`
- `lastRun(?LastRunOptions $options = null): RunClient`
- `runs(): RunCollectionClient`
- `webhooks(): NestedWebhookCollectionClient` — read-only.

```php
$run = $client->task('me~my-task')->call(['message' => 'override'], null, 120);
$input = $client->task('me~my-task')->getInput();
```
