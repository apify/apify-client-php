# Tasks

A task is an Actor run with a stored input. Snippets assume
`$client = new ApifyClient('my-api-token');` and imported types.

## Task collection — `$client->tasks()`

- `list(?ListOptions $options = null): PaginationList`
- `iterate(?ListOptions $options = null, ?int $chunkSize = null): iterable`: lazily yields the tasks. The `limit` option caps the total (unset for all), `$chunkSize` is the page size.
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
- `publish(): Task`, `unpublish(): Task`: publish or unpublish the public landing page by setting `isPublic`.
  Both need write permission to the Actor of the task. Publishing also needs a public Actor and the
  `publicConfig.inputSchemaFields` and `publicConfig.datasetView` of the task. An Actor can have 10 published
  tasks and an account 100. If a condition fails, nothing changes.
- `start(mixed $input = null, ?TaskStartOptions $options = null): ActorRun`: `$options->waitForResources` is as
  for [`ActorClient::start()`](actors.md).
- `call(mixed $input = null, ?TaskStartOptions $options = null, ?int $waitSecs = null, int|float|string|null $timeoutSecs = null): ActorRun`
- `getInput(): mixed` (throws if the task does not exist), `updateInput(mixed $input): mixed`
- `lastRun(?LastRunOptions $options = null): RunClient`
- `runs(): RunCollectionClient`
- `webhooks(): NestedWebhookCollectionClient`: read-only.

```php
$run = $client->task('me~my-task')->call(['message' => 'override'], null, 120);
$input = $client->task('me~my-task')->getInput();
$client->task('me~my-task')->publish();
```
