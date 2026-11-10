# Webhooks & dispatches

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types.

## Webhook collection — `$client->webhooks()`

- `list(?ListOptions $options = null): PaginationList`
- `iterate(?ListOptions $options = null, ?int $chunkSize = null): iterable` — lazily yields the webhooks. The `limit` option caps the total (unset for all), `$chunkSize` is the page size.
- `create(mixed $webhook): Webhook`

The webhooks of an Actor or task (`$client->actor($id)->webhooks()`, `$client->task($id)->webhooks()`) are
read-only, with `list()` and `iterate()`. Create a webhook in this collection, and target an Actor or task in its
`condition`.

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

- Collection: `list(?ListOptions $options = null): PaginationList`, `iterate(?ListOptions $options = null, ?int $chunkSize = null): iterable` — lazily yields the dispatches. The `limit` option caps the total, `$chunkSize` is the page size.
- Single: `get(): ?WebhookDispatch`.

```php
$page = $client->webhookDispatches()->list(new ListOptions(limit: 5));
$dispatch = $client->webhookDispatch('DISPATCH_ID')->get();

foreach ($client->webhookDispatches()->iterate(new ListOptions(), 50) as $d) {
    echo $d->getId() . PHP_EOL;
}
```
