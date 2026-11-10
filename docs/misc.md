# Store, users & logs

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types.

## Apify Store — `$client->store()`

- `list(?StoreListOptions $options = null): PaginationList` — one page of Store Actors.
- `iterate(?StoreListOptions $options = null, ?int $chunkSize = null): iterable` — lazily yields the matching Actors. The `limit` option caps the total (unset for all), `$chunkSize` is the page size.

```php
$page = $client->store()->list(new StoreListOptions(search: 'scraper', limit: 10));

$shown = 0;
// 50 is the page size. The limit option would cap the total.
foreach ($client->store()->iterate(new StoreListOptions(search: 'scraper'), 50) as $item) {
    echo $item->getName() . PHP_EOL;
    if (++$shown >= 5) {
        break;
    }
}
```

## Users — `$client->me()` / `$client->user($id)`

- `get(): ?User` — for `me()`, the private account details are in `toArray()`.
- `monthlyUsage(?string $date = null): array` — the monthly usage of the cycle that contains `$date` (`YYYY-MM-DD`), by default the current month.
- `limits(): array`, `updateLimits(mixed $newLimits): void` — the account limits. These three methods work for `me()` and for any user ID the token may access.

```php
$me = $client->me()->get();
$usage = $client->me()->monthlyUsage('2026-06-01');
$limits = $client->me()->limits();
$publicProfile = $client->user('some-username')->get();
```

## Logs — `$client->log($buildOrRunId)`

- `get(?LogOptions $options = null): ?string` — the log as text, with the exact bytes the API sent.
- `stream(?LogOptions $options = null): StreamInterface` — a live stream of the log.

```php
$log = $client->log('RUN_OR_BUILD_ID')->get(new LogOptions(raw: true));
```
