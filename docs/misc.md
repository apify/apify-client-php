# Store, users & logs

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types.

## Apify Store — `$client->store()`

- `list(?StoreListOptions $options = null): PaginationList` — one page of Store Actors.
- `iterate(?StoreListOptions $options): iterable` — lazily iterate all matching Actors, paging on demand.

```php
$page = $client->store()->list(new StoreListOptions(search: 'scraper', limit: 10));

$shown = 0;
foreach ($client->store()->iterate(new StoreListOptions(limit: 50)) as $item) {
    echo $item->getName() . PHP_EOL;
    if (++$shown >= 5) {
        break;
    }
}
```

## Users — `$client->me()` / `$client->user($id)`

- `get(): ?User` — for `me()`, private account details are available via `toArray()`.
- `monthlyUsage(?string $date = null): array` — current-account monthly usage (only for `me()`).
- `limits(): array`, `updateLimits(mixed $newLimits): void` — account limits (only for `me()`).

```php
$me = $client->me()->get();
$usage = $client->me()->monthlyUsage('2026-06-01');
$limits = $client->me()->limits();
$publicProfile = $client->user('some-username')->get();
```

## Logs — `$client->log($buildOrRunId)`

- `get(?LogOptions $options = null): ?string` — the full log as text.
- `stream(?LogOptions $options = null): StreamInterface` — a live stream of the log.

```php
$log = $client->log('RUN_OR_BUILD_ID')->get(new LogOptions(raw: true));
```
