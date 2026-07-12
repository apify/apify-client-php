# Webhooks & dispatches

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types.

## Webhook collection — `$client->webhooks()`

- `list(?ListOptions $options = null): PaginationList`
- `iterate(?ListOptions $options = null, ?int $chunkSize = null): iterable` — lazily iterate all webhooks, paging on demand. The options' `limit` caps the total number yielded across all pages (unset = all); `$chunkSize` is the per-page size.
- `create(mixed $webhook): Webhook`

Webhooks nested under an Actor or task (`$client->actor($id)->webhooks()`,
`$client->task($id)->webhooks()`) are **read-only** — they support `list(...)` and `iterate(...)`
only. Create webhooks through the account-wide collection, targeting an Actor or task via the
webhook's `condition`.

```php
$webhook = $client->webhooks()->create([
    'eventTypes' => ['ACTOR.RUN.SUCCEEDED'],
    'condition' => ['actorId' => 'ACTOR_ID'],
    'requestUrl' => 'https://example.com/webhook',
]);
```

## A single webhook — `$client->webhook($id)`

- `get(): ?Webhook`, `update(mixed $newFields): Webhook`, `delete(): void`
- `test(): WebhookDispatch` — dispatch immediately.
- `dispatches(): WebhookDispatchCollectionClient`

```php
$dispatch = $client->webhook('WEBHOOK_ID')->test();
$client->webhook('WEBHOOK_ID')->dispatches()->list(new ListOptions(limit: 10));
```

## Webhook dispatches — `$client->webhookDispatches()` / `$client->webhookDispatch($id)`

- Collection: `list(?ListOptions $options = null): PaginationList`, `iterate(?ListOptions $options = null, ?int $chunkSize = null): iterable` — lazily iterate all dispatches, paging on demand (options' `limit` caps the total; `$chunkSize` is the per-page size).
- Single: `get(): ?WebhookDispatch`.

```php
$page = $client->webhookDispatches()->list(new ListOptions(limit: 5));
$dispatch = $client->webhookDispatch('DISPATCH_ID')->get();

foreach ($client->webhookDispatches()->iterate(new ListOptions(), 50) as $d) {
    echo $d->getId() . PHP_EOL;
}
```
