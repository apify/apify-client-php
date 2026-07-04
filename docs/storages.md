# Storages: datasets, key-value stores, request queues

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types (see
[Namespaces](README.md#namespaces)). The storage collections all support
`list(?StorageListOptions $options = null)` and `getOrCreate(?string $name = null)`; the dataset and
key-value-store collections additionally accept an optional `?array $schema` on `getOrCreate`
(request queues take only a name). The same storage can be reached from a run
(`$client->run($id)->dataset()`, etc.).

## Datasets

Collection — `$client->datasets()`: `list(?StorageListOptions $options = null): PaginationList`,
`getOrCreate(?string $name = null, ?array $schema = null): Dataset`.

Single — `$client->dataset($id)`:

- `get(): ?Dataset`, `update(mixed $newFields): Dataset`, `delete(): void`
- `listItems(?DatasetListItemsOptions $options = null): PaginationList` — items decoded to arrays.
- `downloadItems(DownloadItemsFormat $format, ?DatasetDownloadOptions $options = null): string` — raw export bytes.
- `pushItems(mixed $items): void`
- `getStatistics(): ?array`
- `createItemsPublicUrl(?DatasetListItemsOptions $options = null, ?int $expiresInSecs = null): string`

```php
$dataset = $client->datasets()->getOrCreate('my-dataset');
$client->dataset($dataset->getId())->pushItems([['url' => 'https://a.com'], ['url' => 'https://b.com']]);
$items = $client->dataset($dataset->getId())->listItems(new DatasetListItemsOptions(limit: 100));
$csv = $client->dataset($dataset->getId())->downloadItems(DownloadItemsFormat::CSV, new DatasetDownloadOptions(bom: true));
```

## Key-value stores

Collection — `$client->keyValueStores()`: `list(?StorageListOptions $options = null): PaginationList`,
`getOrCreate(?string $name = null, ?array $schema = null): KeyValueStore`.

Single — `$client->keyValueStore($id)`:

- `get(): ?KeyValueStore`, `update(mixed $newFields): KeyValueStore`, `delete(): void`
- `listKeys(?ListKeysOptions $options = null): KeyValueStoreKeysPage`
- `recordExists(string $key): bool`
- `getRecord(string $key, ?GetRecordOptions $options = null): ?KeyValueStoreRecord`
- `setRecord(string $key, string $value, string $contentType, ?SetRecordOptions $options = null): void`
- `setRecordJson(string $key, mixed $value): void`
- `deleteRecord(string $key): void`
- `getRecordPublicUrl(string $key): string`, `createKeysPublicUrl(?ListKeysOptions, ?int $expiresInSecs): string`

```php
$store = $client->keyValueStores()->getOrCreate('my-store');
$client->keyValueStore($store->getId())->setRecordJson('OUTPUT', ['answer' => 42]);
$record = $client->keyValueStore($store->getId())->getRecord('OUTPUT');
echo $record?->getValue() ?? '';
```

## Request queues

Collection — `$client->requestQueues()`: `list(?StorageListOptions $options = null): PaginationList`,
`getOrCreate(?string $name = null): RequestQueue`.

A specific queue client is obtained with `$client->requestQueue($id, ?RequestQueueClientOptions $options = null)`.
The optional `RequestQueueClientOptions` sets a stable `clientKey` (required to operate on locks the
client created) and/or a per-request `timeoutSecs` for this queue's calls.

Single — `$client->requestQueue($id)`:

- `get(): ?RequestQueue`, `update(mixed $newFields): RequestQueue`, `delete(): void`
- `listHead(?int $limit = null): RequestQueueHead`
- `addRequest(RequestQueueRequest $request, bool $forefront = false): RequestQueueOperationInfo`
- `getRequest(string $id): ?RequestQueueRequest`, `updateRequest(RequestQueueRequest $request, bool $forefront = false): RequestQueueOperationInfo`, `deleteRequest(string $id): void`
- `batchAddRequests(array $requests, bool $forefront = false, ?BatchAddRequestsOptions $options = null): BatchAddResult` — every request must have a non-empty `uniqueKey`; input is split into batches of at most 25 requests that also respect the ~9 MiB payload limit.
- `batchDeleteRequests(mixed $requests): array`
- `listRequests(?ListRequestsOptions $options = null): array`, `paginateRequests(?PaginateRequestsOptions $options = null): iterable`
- `listAndLockHead(int $lockSecs, ?int $limit = null): array`, `prolongRequestLock(...)`, `deleteRequestLock(...)`, `unlockRequests(): array`
- `withClientKey(string $clientKey): RequestQueueClient`

`paginateRequests()` accepts a `PaginateRequestsOptions` with `limit` (total across all pages),
`maxPageLimit` (page size), `exclusiveStartId`/`cursor` (starting point) and `filter`
(`locked`/`pending`).

```php
$queue = $client->requestQueues()->getOrCreate('my-queue');
$client->requestQueue($queue->getId())->addRequest(new RequestQueueRequest('https://example.com', 'example'));
foreach ($client->requestQueue($queue->getId())->paginateRequests(new PaginateRequestsOptions(maxPageLimit: 100)) as $request) {
    echo $request->getUrl() . PHP_EOL;
}
```
