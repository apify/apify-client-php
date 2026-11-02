# Schedules

Schedules automatically start Actor or task runs at specified times. Snippets assume
`$client = new ApifyClient('my-api-token');` and imported types.

## Schedule collection — `$client->schedules()`

- `list(?ListOptions $options = null): PaginationList`
- `iterate(?ListOptions $options = null, ?int $chunkSize = null): iterable` — lazily iterate all schedules, paging on demand. The options' `limit` caps the total number yielded across all pages (unset = all); `$chunkSize` is the per-page size.
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
- `getLog(): array` — the schedule's invocation log: a list with one entry per invocation, each an
  array with `message`, `level` and `createdAt` (an empty list if the schedule has not run yet). Throws
  if the schedule itself does not exist (a 404 here cannot mean anything else).

```php
$updated = $client->schedule('SCHEDULE_ID')->update(['cronExpression' => '0 12 * * *']);
foreach ($client->schedule('SCHEDULE_ID')->getLog() as $entry) {
    echo $entry['createdAt'] . ' ' . $entry['message'] . PHP_EOL;
}
```
