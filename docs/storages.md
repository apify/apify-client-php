# Storages: datasets, key-value stores, request queues

Snippets assume `$client = new ApifyClient('my-api-token');` and imported types (see
[Namespaces](README.md#namespaces)). Every storage collection has `list(?StorageListOptions $options = null)` and
`getOrCreate(?string $name = null)`. The dataset and key-value store collections also take an optional
`?array $schema` in `getOrCreate`. The storages of a run are reached from the run, for example
`$client->run($id)->dataset()`.

## Datasets

Collection — `$client->datasets()`:

- `list(?StorageListOptions $options = null): PaginationList`
- `iterate(?StorageListOptions $options = null, ?int $chunkSize = null): iterable`: lazily yields the datasets. The `limit` option caps the total (unset for all), `$chunkSize` is the page size.
- `getOrCreate(?string $name = null, ?array $schema = null): Dataset`

Single — `$client->dataset($id)`:

- `get(): ?Dataset`, `update(mixed $newFields): Dataset`, `delete(): void`
- `listItems(?DatasetListItemsOptions $options = null): PaginationList`: one page of items decoded to PHP values.
- `iterateItems(?DatasetListItemsOptions $options = null, ?int $chunkSize = null): iterable`: lazily yields the items. The `limit` option caps the total (unset for all), `$chunkSize` is the page size. Dataset items have no default limit, so without `$chunkSize` the first request loads the whole dataset. The iterator advances by the `getCount()` of a page (the rows the API scanned, or the items when that is larger), not by its number of items, which matters with `skipEmpty`, `clean` and `unwind`.
- `downloadItems(DownloadItemsFormat $format, ?DatasetDownloadOptions $options = null): string`: the export as bytes.
- `streamItems(DownloadItemsFormat $format, ?DatasetDownloadOptions $options = null): StreamInterface`: the export of `downloadItems()` as a PSR-7 stream, so a large export can be read in chunks, for example piped to a file. It is not retried.
- `pushItems(mixed $items): void`
- `getStatistics(): ?array`: throws if the dataset does not exist.
- `createItemsPublicUrl(?DatasetListItemsOptions $options = null, ?int $expiresInSecs = null, ?DownloadItemsFormat $format = null, int|float|string|null $timeoutSecs = null): string`: builds a public URL for downloading the items, with the filters of the options. The URL of a private dataset is signed, and valid for `$expiresInSecs` if given. It must be positive, otherwise `InvalidArgumentException` is thrown before any request. Omit it for a URL that never expires. `$format` is the output format (default `json`). `$timeoutSecs` is for the request that fetches the dataset (default: the `long` tier).

```php
$dataset = $client->datasets()->getOrCreate('my-dataset');
$client->dataset($dataset->getId())->pushItems([['url' => 'https://a.com'], ['url' => 'https://b.com']]);
$items = $client->dataset($dataset->getId())->listItems(new DatasetListItemsOptions(limit: 100));
$csv = $client->dataset($dataset->getId())->downloadItems(DownloadItemsFormat::CSV, new DatasetDownloadOptions(bom: true));

// Lazily iterate every item, fetching pages of 1000 on demand.
foreach ($client->dataset($dataset->getId())->iterateItems(new DatasetListItemsOptions(), 1000) as $item) {
    echo ($item['url'] ?? '') . PHP_EOL;
}

// Stream a large export straight to a file instead of buffering it in memory.
$stream = $client->dataset($dataset->getId())->streamItems(DownloadItemsFormat::CSV);
$out = fopen('output.csv', 'wb');
while (!$stream->eof()) {
    fwrite($out, $stream->read(8192));
}
fclose($out);
```

## Key-value stores

Collection — `$client->keyValueStores()`:

- `list(?StorageListOptions $options = null): PaginationList`
- `iterate(?StorageListOptions $options = null, ?int $chunkSize = null): iterable`: lazily yields the stores. The `limit` option caps the total (unset for all), `$chunkSize` is the page size.
- `getOrCreate(?string $name = null, ?array $schema = null): KeyValueStore`

Single — `$client->keyValueStore($id)`:

- `get(): ?KeyValueStore`, `update(mixed $newFields): KeyValueStore`, `delete(): void`
- `listKeys(?ListKeysOptions $options = null): KeyValueStoreKeysPage`
- `iterateKeys(?ListKeysOptions $options = null, ?int $chunkSize = null): iterable`: lazily yields the keys, following the cursor pages. The `limit` option caps the total (unset for all), `$chunkSize` is the page size (unset for the remaining total, or the API's default without a limit).
- `recordExists(string $key): bool`: returns whether the record exists, without downloading it (a `HEAD` request).
- `getRecord(string $key, ?GetRecordOptions $options = null): ?KeyValueStoreRecord`: `getValue()` returns the bytes the API sent, whatever the content type, so decode a JSON record yourself.
- `setRecord(string $key, string $value, string $contentType, ?SetRecordOptions $options = null, ?string $contentEncoding = null): void`: `$value` is sent as it is, also under `application/json`, so it must already be valid JSON. Use `setRecordJson()` to have a value encoded. `$contentEncoding` is the `Content-Encoding` of a `$value` that is already compressed, for example `gzip`, `br` or `zstd`. The bytes are then sent as they are, without further compression.
- `setRecordJson(string $key, mixed $value, ?SetRecordOptions $options = null): void`: JSON-encodes `$value` and stores it as a JSON record.
- `deleteRecord(string $key): void`: deletes the record.
- `getRecordPublicUrl(string $key, int|float|string|null $timeoutSecs = null): string`: builds a public URL of a record. The URL of a private store is signed, so it works without a token.
- `createKeysPublicUrl(?ListKeysOptions $options = null, ?int $expiresInSecs = null, int|float|string|null $timeoutSecs = null): string`: builds a public URL for listing the keys, with the filters of the options. The URL of a private store is signed, and valid for `$expiresInSecs` if given. It must be positive, otherwise `InvalidArgumentException` is thrown before any request. Omit it for a URL that never expires. `$timeoutSecs` is for the request that fetches the store (default: the `long` tier).

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

// Upload an already-gzipped value without the client compressing it a second time.
$gzipped = gzencode(json_encode(['answer' => 42]));
$client->keyValueStore($store->getId())->setRecord('OUTPUT.json', $gzipped, 'application/json', contentEncoding: 'gzip');
```

## Request queues

Collection — `$client->requestQueues()`:

- `list(?StorageListOptions $options = null): PaginationList`
- `iterate(?StorageListOptions $options = null, ?int $chunkSize = null): iterable`: lazily yields the request queues. The `limit` option caps the total (unset for all), `$chunkSize` is the page size.
- `getOrCreate(?string $name = null): RequestQueue`

A specific queue client is obtained with `$client->requestQueue($id, ?RequestQueueClientOptions $options = null)`.
The optional `RequestQueueClientOptions` sets a stable `clientKey` (required to operate on locks the
client created) and/or a per-request `timeoutSecs` for this queue's calls.

Single — `$client->requestQueue($id)`:

- `get(): ?RequestQueue`, `update(mixed $newFields): RequestQueue`, `delete(): void`
- `listHead(?int $limit = null): RequestQueueHead`
- `addRequest(RequestQueueRequest $request, bool $forefront = false): RequestQueueOperationInfo`: adds a request, at the front with `$forefront`.
- `getRequest(string $id): ?RequestQueueRequest`, `updateRequest(RequestQueueRequest $request, bool $forefront = false): RequestQueueOperationInfo` (with `$forefront`, the request moves to the front), `deleteRequest(string $id): void`
- `batchAddRequests(array $requests, bool $forefront = false, ?BatchAddRequestsOptions $options = null): BatchAddResult`: every request needs a non-empty `uniqueKey`. With `$forefront`, they are added at the front. The input is split into batches of at most 25 requests and about 9 MiB.
- `batchDeleteRequests(array $requests): BatchDeleteResult`: `$requests` is a `list<RequestQueueRequest>` of 1 to 25 entries, each identified by `setId()` or `setUniqueKey()`. Throws `InvalidArgumentException` for an empty list, more than 25 entries, or an entry without an id and a uniqueKey. Larger input is rejected instead of split, since a delete can be repeated.
- `listRequests(?ListRequestsOptions $options = null): RequestQueueRequestsPage`
- `paginateRequests(?PaginateRequestsOptions $options = null): iterable`: lazily yields the `RequestQueueRequest`s, following the cursor pages. See the options below.
- `listAndLockHead(int $lockSecs, ?int $limit = null): LockedRequestQueueHead`: returns up to `$limit` requests and locks them for `$lockSecs` seconds.
- `prolongRequestLock(string $id, int $lockSecs, bool $forefront = false): RequestLockInfo`: extends the lock of a request by `$lockSecs`. With `$forefront`, the request moves to the front when the lock expires.
- `deleteRequestLock(string $id, bool $forefront = false): void`: releases the lock of a request. With `$forefront`, it moves to the front of the queue.
- `unlockRequests(): UnlockRequestsResult`: releases all locks that the client holds on the queue.
- `withClientKey(string $clientKey): RequestQueueClient`

`paginateRequests()` accepts a `PaginateRequestsOptions` with `limit` (total across all pages),
`maxPageLimit` (page size), `exclusiveStartId`/`cursor` (starting point), and `filter`
(`locked`/`pending`).

```php
$queue = $client->requestQueues()->getOrCreate('my-queue');
$client->requestQueue($queue->getId())->addRequest(new RequestQueueRequest('https://example.com', 'example'));
foreach ($client->requestQueue($queue->getId())->paginateRequests(new PaginateRequestsOptions(maxPageLimit: 100)) as $request) {
    echo $request->getUrl() . PHP_EOL;
}
```
