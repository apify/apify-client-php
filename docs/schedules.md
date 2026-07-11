# Schedules

Schedules automatically start Actor or task runs at specified times. Snippets assume
`$client = new ApifyClient('my-api-token');` and imported types.

## Schedule collection — `$client->schedules()`

- `list(?ListOptions $options = null): PaginationList`
- `create(mixed $schedule): Schedule`

```php
$schedule = $client->schedules()->create([
    'name' => 'nightly',
    'cronExpression' => '0 0 * * *',
    'isEnabled' => true,
    'actions' => [],
]);
```

## A single schedule — `$client->schedule($id)`

- `get(): ?Schedule`, `update(mixed $newFields): Schedule`, `delete(): void`
- `getLog(): ?string` — the schedule's invocation log, or `null` if absent.

```php
$updated = $client->schedule('SCHEDULE_ID')->update(['cronExpression' => '0 12 * * *']);
$log = $client->schedule('SCHEDULE_ID')->getLog();
```
