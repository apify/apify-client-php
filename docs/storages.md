# Storages: datasets, key-value stores, request queues

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types (see
[Namespaces](README.md#namespaces)). The storage collections all support
`list(?StorageListOptions $options = null)` and `getOrCreate(?string $name = null)`; the dataset and
key-value-store collections additionally accept an optional `?array $schema` on `getOrCreate`
(request queues take only a name). The same storage can be reached from a run
(`$client->run($id)->dataset()`, etc.).

## Datasets

Collection — `$client->datasets()`:

- `list(?StorageListOptions $options = null): PaginationList`
- `iterate(?StorageListOptions $options = null, ?int $chunkSize = null): iterable` — lazily iterate all datasets, paging on demand. The options' `limit` caps the total number yielded across all pages (unset = all); `$chunkSize` is the per-page size.
- `getOrCreate(?string $name = null, ?array $schema = null): Dataset`

Single — `$client->dataset($id)`:

- `get(): ?Dataset`, `update(mixed $newFields): Dataset`, `delete(): void`
- `listItems(?DatasetListItemsOptions $options = null): PaginationList` — one page of items decoded to PHP values.
- `iterateItems(?DatasetListItemsOptions $options = null, ?int $chunkSize = null): iterable` — lazily iterate all items, paging on demand. The options' `limit` caps the total number of items yielded across all pages (unset = all); `$chunkSize` is the per-page size. Note: server-side item filters (`skipEmpty`, `skipHidden`, `clean`) are applied after `offset`/`limit`, so combining them with multi-page iteration can repeat or skip items (the iterator advances the offset by the post-filter count, matching the reference JS client). Iterate without those filters, or page explicitly with `listItems()` and filter client-side.
- `downloadItems(DownloadItemsFormat $format, ?DatasetDownloadOptions $options = null): string` — raw export bytes.
- `pushItems(mixed $items): void`
- `getStatistics(): ?array`
- `createItemsPublicUrl(?DatasetListItemsOptions $options = null, ?int $expiresInSecs = null): string` — builds a shareable URL for downloading this dataset's items (forwarding the given item filters); for a private dataset it appends an access signature, optionally bounded to `$expiresInSecs`.

```php
$dataset = $client->datasets()->getOrCreate('my-dataset');
$client->dataset($dataset->getId())->pushItems([['url' => 'https://a.com'], ['url' => 'https://b.com']]);
$items = $client->dataset($dataset->getId())->listItems(new DatasetListItemsOptions(limit: 100));
$csv = $client->dataset($dataset->getId())->downloadItems(DownloadItemsFormat::CSV, new DatasetDownloadOptions(bom: true));

// Lazily iterate every item, fetching pages of 1000 on demand.
foreach ($client->dataset($dataset->getId())->iterateItems(new DatasetListItemsOptions(), 1000) as $item) {
    echo ($item['url'] ?? '') . PHP_EOL;
}
```

## Key-value stores

Collection — `$client->keyValueStores()`:

- `list(?StorageListOptions $options = null): PaginationList`
- `iterate(?StorageListOptions $options = null, ?int $chunkSize = null): iterable` — lazily iterate all stores, paging on demand. The options' `limit` caps the total number yielded across all pages (unset = all); `$chunkSize` is the per-page size.
- `getOrCreate(?string $name = null, ?array $schema = null): KeyValueStore`

Single — `$client->keyValueStore($id)`:

- `get(): ?KeyValueStore`, `update(mixed $newFields): KeyValueStore`, `delete(): void`
- `listKeys(?ListKeysOptions $options = null): KeyValueStoreKeysPage`
- `iterateKeys(?ListKeysOptions $options = null): iterable` — lazily iterate all keys, following cursor pagination (`exclusiveStartKey`/`nextExclusiveStartKey`). The options' `limit` caps the total number of keys yielded across all pages (unset = all); there is no separate page-size argument (the per-page size follows the remaining cap, like the reference client).
- `recordExists(string $key): bool` — reports whether a record with the given key exists, without downloading its value (a `HEAD` request).
- `getRecord(string $key, ?GetRecordOptions $options = null): ?KeyValueStoreRecord`
- `setRecord(string $key, string $value, string $contentType, ?SetRecordOptions $options = null): void`
- `setRecordJson(string $key, mixed $value): void` — convenience over `setRecord()` that JSON-encodes `$value` and stores it with a JSON content type.
- `deleteRecord(string $key): void` — permanently removes the record with the given key.
- `getRecordPublicUrl(string $key): string` — builds a shareable URL for downloading a single record; for a private store it appends an access signature so the URL works without an API token.
- `createKeysPublicUrl(?ListKeysOptions $options = null, ?int $expiresInSecs = null): string` — builds a shareable URL for listing this store's keys (forwarding the given key filters); for a private store it appends an access signature, optionally bounded to `$expiresInSecs`.

```php
$store = $client->keyValueStores()->getOrCreate('my-store');
$client->keyValueStore($store->getId())->setRecordJson('OUTPUT', ['answer' => 42]);
$record = $client->keyValueStore($store->getId())->getRecord('OUTPUT');
// getRecord() returns a KeyValueStoreRecord; getValue() gives the raw string - decode it yourself when it is JSON.
$decoded = json_decode($record?->getValue() ?? 'null', true);
echo ($decoded['answer'] ?? '') . PHP_EOL;

// Lazily iterate every key (cursor-paginated) and read each record.
foreach ($client->keyValueStore($store->getId())->iterateKeys() as $key) {
    echo $key->getKey() . PHP_EOL;
}
```

## Request queues

Collection — `$client->requestQueues()`:

- `list(?StorageListOptions $options = null): PaginationList`
- `iterate(?StorageListOptions $options = null, ?int $chunkSize = null): iterable` — lazily iterate all request queues, paging on demand. The options' `limit` caps the total number yielded across all pages (unset = all); `$chunkSize` is the per-page size.
- `getOrCreate(?string $name = null): RequestQueue`

A specific queue client is obtained with `$client->requestQueue($id, ?RequestQueueClientOptions $options = null)`.
The optional `RequestQueueClientOptions` sets a stable `clientKey` (required to operate on locks the
client created) and/or a per-request `timeoutSecs` for this queue's calls.

Single — `$client->requestQueue($id)`:

- `get(): ?RequestQueue`, `update(mixed $newFields): RequestQueue`, `delete(): void`
- `listHead(?int $limit = null): RequestQueueHead`
- `addRequest(RequestQueueRequest $request, bool $forefront = false): RequestQueueOperationInfo` — adds a request to the queue; when `$forefront` is `true` it is added to the front (handled before the rest) instead of the back.
- `getRequest(string $id): ?RequestQueueRequest`, `updateRequest(RequestQueueRequest $request, bool $forefront = false): RequestQueueOperationInfo` (with `$forefront` `true` the updated request is moved to the front of the queue), `deleteRequest(string $id): void`
- `batchAddRequests(array $requests, bool $forefront = false, ?BatchAddRequestsOptions $options = null): BatchAddResult` — every request must have a non-empty `uniqueKey`; with `$forefront` `true` the requests are added to the front of the queue; input is split into batches of at most 25 requests that also respect the ~9 MiB payload limit.
- `batchDeleteRequests(mixed $requests): array` — `$requests` is a list of entries that each identify a request to delete (e.g. by `id` or `uniqueKey`); returns the raw batch result as a decoded `array<string,mixed>`.
- `listRequests(?ListRequestsOptions $options = null): array` — returns the raw paginated response as a decoded `array<string,mixed>`.
- `paginateRequests(?PaginateRequestsOptions $options = null): iterable` — lazily iterate the queue's requests, yielding `RequestQueueRequest` instances and following cursor pagination (see the options note below).
- `listAndLockHead(int $lockSecs, ?int $limit = null): array` — atomically returns and locks up to `$limit` requests for `$lockSecs` seconds; returns the raw locked-head object as a decoded `array<string,mixed>`.
- `prolongRequestLock(string $id, int $lockSecs, bool $forefront = false): array` — extends a request's lock by `$lockSecs`; with `$forefront` `true` the request is placed at the front of the queue once its lock expires; returns the raw response as a decoded `array<string,mixed>`.
- `deleteRequestLock(string $id, bool $forefront = false): void` — releases the lock on a single request; with `$forefront` `true` the request is returned to the front of the queue.
- `unlockRequests(): array` — releases all locks the client holds on this queue; returns the raw response as a decoded `array<string,mixed>`.
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
