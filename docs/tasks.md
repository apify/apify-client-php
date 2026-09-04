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
  publishing additionally requires the Actor to be public, to have fewer than 50 already-published
  tasks, and the task's `publicConfig.inputSchemaFields`/`publicConfig.datasetView` to be set.
- `start(mixed $input = null, ?TaskStartOptions $options = null): ActorRun`
- `call(mixed $input = null, ?TaskStartOptions $options = null, ?int $waitSecs = null): ActorRun`
- `getInput(): mixed`, `updateInput(mixed $input): mixed`
- `lastRun(?LastRunOptions $options = null): RunClient`
- `runs(): RunCollectionClient`
- `webhooks(): NestedWebhookCollectionClient` — read-only.

```php
$run = $client->task('me~my-task')->call(['message' => 'override'], null, 120);
$input = $client->task('me~my-task')->getInput();
$client->task('me~my-task')->publish();
```
