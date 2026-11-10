# Schedules

A schedule starts Actor or task runs at set times. Snippets assume
`$client = new ApifyClient('my-api-token');` and imported types.

## Schedule collection — `$client->schedules()`

- `list(?ListOptions $options = null): PaginationList`
- `iterate(?ListOptions $options = null, ?int $chunkSize = null): iterable` — lazily yields the schedules. The `limit` option caps the total (unset for all), `$chunkSize` is the page size.
- `create(mixed $schedule): Schedule`

```php
$schedule = $client->schedules()->create([
    'name' => 'nightly',
    'cronExpression' => '0 0 * * *',
    'isEnabled' => true,
    'actions' => [],
]);

foreach ($client->schedules()->iterate(new ListOptions(), 50) as $s) {
    echo $s->getId() . PHP_EOL;
}
```

## A single schedule — `$client->schedule($id)`

- `get(): ?Schedule`, `update(mixed $newFields): Schedule`, `delete(): void`
- `getLog(): array` — the invocation log: a list with one array per invocation, with `message`, `level` and
  `createdAt`. It is empty if the schedule has not run. Throws if the schedule does not exist.

```php
$updated = $client->schedule('SCHEDULE_ID')->update(['cronExpression' => '0 12 * * *']);
foreach ($client->schedule('SCHEDULE_ID')->getLog() as $entry) {
    echo $entry['createdAt'] . ' ' . $entry['message'] . PHP_EOL;
}
```
