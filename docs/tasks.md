# Tasks

Tasks are pre-configured Actor runs with stored input. Snippets assume
`$client = new ApifyClient('my-api-token');` and imported types.

## Task collection — `$client->tasks()`

- `list(?ListOptions $options = null): PaginationList`
- `iterate(?ListOptions $options = null, ?int $chunkSize = null): iterable` — lazily iterate all tasks, paging on demand. The options' `limit` caps the total number yielded across all pages (unset = all); `$chunkSize` is the per-page size.
- `create(mixed $task): Task`

```php
$task = $client->tasks()->create([
    'actId' => 'apify/hello-world',
    'name' => 'my-task',
    'input' => ['message' => 'hello'],
]);

foreach ($client->tasks()->iterate(new ListOptions(), 50) as $t) {
    echo $t->getId() . PHP_EOL;
}
```

## A single task — `$client->task($id)`

- `get(): ?Task`, `update(mixed $newFields): Task`, `delete(): void`
- `publish(): Task`, `unpublish(): Task` — publish/unpublish the task's public landing page, by
  setting `isPublic` through `update()`. Both require write permission to the task's Actor;
  publishing additionally requires the Actor to be public and the task's
  `publicConfig.inputSchemaFields`/`publicConfig.datasetView` to be set. An Actor can have up to 10
  published tasks and an account up to 100; if the conditions aren't met, the request fails and
  nothing is changed.
- `start(mixed $input = null, ?TaskStartOptions $options = null): ActorRun`
- `call(mixed $input = null, ?TaskStartOptions $options = null, ?int $waitSecs = null): ActorRun`
- `getInput(): mixed` (throws if the task itself does not exist — a 404 here cannot mean anything
  else), `updateInput(mixed $input): mixed`
- `lastRun(?LastRunOptions $options = null): RunClient`
- `runs(): RunCollectionClient`
- `webhooks(): NestedWebhookCollectionClient` — read-only.

```php
$run = $client->task('me~my-task')->call(['message' => 'override'], null, 120);
$input = $client->task('me~my-task')->getInput();
$client->task('me~my-task')->publish();
```
