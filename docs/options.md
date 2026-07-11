# Options reference

Option objects live under the `Apify\Client\Options\` namespace. They use named constructor
arguments; every field is optional unless noted, and an unset field means "use the API default".
Pass only the arguments you need:

```php
$options = new ActorListOptions(my: true, limit: 10);
```

## Listing and pagination

### Manual offset paging with `withPagination()`
The offset-based options classes — `ListOptions`, `ActorListOptions`, `StorageListOptions`,
`StoreListOptions` and `DatasetListItemsOptions` — each expose a helper
`withPagination(?int $offset, ?int $limit): self`.

It returns a copy of the options with the given `offset` and `limit`, preserving every other field.
The `iterate()` helpers use it internally to request successive pages, but you can also call it to
page manually through `list()` results — most useful for the Apify Store, whose collection is
otherwise only pageable via `iterate()`:

```php
$options = new StoreListOptions(search: 'scraper');
for ($offset = 0; ; $offset += 100) {
    $page = $client->store()->list($options->withPagination($offset, 100));
    foreach ($page->getItems() as $item) {
        // process each Actor
    }
    if ($page->getCount() < 100) {
        break; // last page reached
    }
}
```

### `ListOptions`
Shared pagination/ordering controls used by most `list()` methods (builds, runs, tasks, schedules,
webhooks, dispatches, Actor versions).
| Field | Type | Description |
|---|---|---|
| `offset` | `?int` | Number of items to skip. |
| `limit` | `?int` | Maximum number of items to return. |
| `desc` | `?bool` | Return items newest-first. |

Supports [`withPagination($offset, $limit)`](#manual-offset-paging-with-withpagination).

### `ActorListOptions`
| Field | Type | Description |
|---|---|---|
| `offset` | `?int` | Number of Actors to skip. |
| `limit` | `?int` | Maximum number of Actors to return. |
| `desc` | `?bool` | Return Actors newest-first. |
| `my` | `?bool` | Return only Actors owned by the current user. |
| `sortBy` | `?string` | The sort field (e.g. `createdAt`, `stats.lastRunStartedAt`). |

Supports [`withPagination($offset, $limit)`](#manual-offset-paging-with-withpagination).

### `StorageListOptions`
Used when listing datasets, key-value stores and request queues.
| Field | Type | Description |
|---|---|---|
| `offset` | `?int` | Number of items to skip. |
| `limit` | `?int` | Maximum number of items to return. |
| `desc` | `?bool` | Return items newest-first. |
| `unnamed` | `?bool` | Include unnamed storages in the result. |
| `ownership` | `?string` | Filter by ownership (e.g. `OWNED`, `ACCESSIBLE`). |

Supports [`withPagination($offset, $limit)`](#manual-offset-paging-with-withpagination).

### `RunListOptions`
Extra filters for `runs()->list()`, combined with a `ListOptions`.
| Field | Type | Description |
|---|---|---|
| `status` | `list<string>\|null` | Filter by one or more run statuses (e.g. `SUCCEEDED`, `RUNNING`); sent comma-separated. |
| `startedAfter` | `?string` | Only runs started after this ISO-8601 timestamp. |
| `startedBefore` | `?string` | Only runs started before this ISO-8601 timestamp. |

### `StoreListOptions`
For `store()->list()` / `store()->iterate()`.
| Field | Type | Description |
|---|---|---|
| `offset` | `?int` | Number of Actors to skip. |
| `limit` | `?int` | Maximum number of Actors to return. When iterating, caps the total across all pages (the per-page size is `iterate()`'s separate `$chunkSize` argument). |
| `search` | `?string` | Full-text search query. |
| `sortBy` | `?string` | The sort field (e.g. `popularity`, `newest`). |
| `category` | `?string` | Filter Actors by category. |
| `username` | `?string` | Filter Actors by owner username. |
| `pricingModel` | `?string` | Filter by pricing model (`FREE`, `FLAT_PRICE_PER_MONTH`, `PRICE_PER_DATASET_ITEM`, `PAY_PER_EVENT`). |
| `includeUnrunnableActors` | `?bool` | Include Actors the current user cannot run. |
| `allowsAgenticUsers` | `?bool` | Filter to Actors that allow agentic users. |
| `responseFormat` | `?string` | The response format (`full`, `agent`). |

Supports [`withPagination($offset, $limit)`](#manual-offset-paging-with-withpagination).

### `LastRunOptions`
For `actor()->lastRun()` / `task()->lastRun()`.
| Field | Type | Description |
|---|---|---|
| `status` | `?string` | Restrict to the last run with this status (e.g. `SUCCEEDED`). |
| `origin` | `?string` | Restrict to the last run started via this origin. |

## Running Actors and tasks

### `ActorStartOptions`
For `actor()->start()` / `actor()->call()`.
| Field | Type | Description |
|---|---|---|
| `build` | `?string` | Tag or number of the build to run (e.g. `latest`, `0.1.2`). |
| `memoryMbytes` | `?int` | Memory in megabytes allocated for the run. |
| `timeoutSecs` | `?int` | Run timeout in seconds (`0` means no timeout). |
| `waitForFinish` | `?int` | Max seconds to wait server-side for the run to finish (max 60). |
| `maxItems` | `?int` | Max number of dataset items to charge (pay-per-result Actors). |
| `maxTotalChargeUsd` | `?float` | Max total charge in USD (pay-per-event Actors). |
| `contentType` | `?string` | Content type of the input body (defaults to `application/json`). |
| `restartOnError` | `?bool` | Restart the run if it fails. |
| `forcePermissionLevel` | `?string` | Override the Actor's permission level (`LIMITED_PERMISSIONS`/`FULL_PERMISSIONS`). |
| `webhooks` | `array\|null` | Ad-hoc webhooks to attach to the run (base64-encoded before sending). |

### `TaskStartOptions`
For `task()->start()` / `task()->call()`. Same fields as `ActorStartOptions` except it has no
`contentType` or `forcePermissionLevel`: `build`, `memoryMbytes`, `timeoutSecs`, `waitForFinish`,
`maxItems`, `maxTotalChargeUsd`, `restartOnError`, `webhooks`.

### `ValidateInputOptions`
For `actor()->validateInput()`.
| Field | Type | Description |
|---|---|---|
| `build` | `?string` | Tag or number of the build whose input schema is used for validation. |
| `contentType` | `?string` | Content type of the input body (defaults to `application/json`). |

### `MetamorphOptions`
For `run()->metamorph()`.
| Field | Type | Description |
|---|---|---|
| `build` | `?string` | Optionally pin the target Actor's build (unset for default). |
| `contentType` | `?string` | Content type of the input body (defaults to `application/json`). |

### `RunResurrectOptions`
For `run()->resurrect()`.
| Field | Type | Description |
|---|---|---|
| `build` | `?string` | Tag or number of the build to resurrect with. |
| `memoryMbytes` | `?int` | Memory in megabytes to allocate. |
| `timeoutSecs` | `?int` | Run timeout in seconds. |
| `maxItems` | `?int` | Max dataset items to charge (pay-per-result Actors). |
| `maxTotalChargeUsd` | `?float` | Max total charge in USD (pay-per-event Actors). |
| `restartOnError` | `?bool` | Restart the run if it fails. |

### `RunChargeOptions`
For `run()->charge()` (pay-per-event Actors). `eventName` is **required**.
| Field | Type | Description |
|---|---|---|
| `eventName` | `string` | Name of the event to charge for. |
| `count` | `?int` | Number of times to charge the event (defaults to 1). |
| `idempotencyKey` | `?string` | Deduplicates the charge across retries; auto-generated if unset. |

## Builds

### `ActorBuildOptions`
For `actor()->build()`.
| Field | Type | Description |
|---|---|---|
| `betaPackages` | `?bool` | Use beta versions of Apify packages. |
| `tag` | `?string` | Tag to apply to the build (e.g. `latest`). |
| `useCache` | `?bool` | Whether to use the Docker build cache (default true). |
| `waitForFinish` | `?int` | Max seconds to wait server-side for the build (max 60). |

## Datasets

### `DatasetListItemsOptions`
For `dataset()->listItems()` and `createItemsPublicUrl()`.
| Field | Type | Description |
|---|---|---|
| `offset` | `?int` | Number of items to skip. |
| `limit` | `?int` | Maximum number of items to return. |
| `desc` | `?bool` | Return items newest-first. |
| `fields` | `list<string>\|null` | Restrict the output to these fields. |
| `outputFields` | `list<string>\|null` | Positionally rename the selected `fields` (requires `fields`). |
| `omit` | `list<string>\|null` | Exclude these fields from the output. |
| `skipEmpty` | `?bool` | Skip empty items. |
| `skipHidden` | `?bool` | Skip hidden fields (those starting with `#`). |
| `clean` | `?bool` | Return only clean (non-empty, non-hidden) items. |
| `unwind` | `list<string>\|null` | Expand these fields (each array element becomes a separate item). |
| `flatten` | `list<string>\|null` | Flatten these nested fields into dot-notation keys. |
| `view` | `?string` | Select a predefined dataset view for field selection. |
| `simplified` | `?bool` | Return simplified (flattened, cleaned) items. |
| `skipFailedPages` | `?bool` | Skip items that come from failed pages. |
| `signature` | `?string` | A pre-shared URL signature granting access without an API token. |

Supports [`withPagination($offset, $limit)`](#manual-offset-paging-with-withpagination).

### `DatasetDownloadOptions`
For `dataset()->downloadItems()` (export formatting on top of the filtering above).
| Field | Type | Description |
|---|---|---|
| `items` | `?DatasetListItemsOptions` | The shared filtering/projection options. |
| `attachment` | `?bool` | Set `Content-Disposition: attachment` on the response. |
| `bom` | `?bool` | Prepend a UTF-8 BOM (useful for Excel-compatible CSV). |
| `delimiter` | `?string` | The CSV field delimiter (default `,`). |
| `skipHeaderRow` | `?bool` | Omit the CSV header row. |
| `xmlRoot` | `?string` | Name of the root XML element (default `items`). |
| `xmlRow` | `?string` | Name of the per-item XML element (default `item`). |
| `feedTitle` | `?string` | Title used for RSS/Atom feed exports. |
| `feedDescription` | `?string` | Description used for RSS/Atom feed exports. |

`DownloadItemsFormat` is an enum selecting the export format: `JSON`, `JSONL`, `CSV`, `HTML`, `XML`,
`RSS`, `XLSX`.

## Key-value stores

### `ListKeysOptions`
For `keyValueStore()->listKeys()` and `createKeysPublicUrl()`.
| Field | Type | Description |
|---|---|---|
| `limit` | `?int` | Maximum number of keys to return. |
| `exclusiveStartKey` | `?string` | List keys after this one (for pagination). |
| `prefix` | `?string` | Restrict the listing to keys with this prefix. |
| `collection` | `?string` | Restrict the listing to a named collection of keys. |
| `signature` | `?string` | A pre-shared URL signature granting access without an API token. |

### `GetRecordOptions`
For `keyValueStore()->getRecord()`.
| Field | Type | Description |
|---|---|---|
| `attachment` | `?bool` | Controls `Content-Disposition: attachment` (default `true`; pass `null` to omit). |
| `signature` | `?string` | A pre-shared URL signature granting access without an API token. |

### `SetRecordOptions`
For `keyValueStore()->setRecord()`.
| Field | Type | Description |
|---|---|---|
| `timeoutSecs` | `?int` | Per-request timeout for the upload (capped at the client's overall request timeout). |
| `doNotRetryTimeouts` | `bool` | If `true`, do not retry the upload on request timeout (default `false`). |

## Request queues

### `RequestQueueClientOptions`
Passed to `requestQueue($id, ...)` to configure a specific queue client.
| Field | Type | Description |
|---|---|---|
| `clientKey` | `?string` | A stable client key (required to operate on locks the same client took). |
| `timeoutSecs` | `?float` | Per-request timeout for this queue's calls. |

### `ListRequestsOptions`
For `requestQueue()->listRequests()`.
| Field | Type | Description |
|---|---|---|
| `limit` | `?int` | Maximum number of requests to return. |
| `exclusiveStartId` | `?string` | List requests after this ID. |
| `cursor` | `?string` | An opaque pagination cursor (alternative to `exclusiveStartId`). |
| `filter` | `list<string>\|null` | Restrict to `locked` and/or `pending` requests. |

### `PaginateRequestsOptions`
For `requestQueue()->paginateRequests()` (lazy iteration across pages).
| Field | Type | Description |
|---|---|---|
| `limit` | `?int` | Maximum total number of requests to iterate across all pages (`null` for no bound). |
| `maxPageLimit` | `?int` | Maximum number of requests fetched per page. |
| `exclusiveStartId` | `?string` | Start after this request ID (first page only; mutually exclusive with `cursor`). |
| `cursor` | `?string` | An opaque pagination cursor (mutually exclusive with `exclusiveStartId`). |
| `filter` | `list<string>\|null` | Restrict to `locked` and/or `pending` requests. |

### `BatchAddRequestsOptions`
For `requestQueue()->batchAddRequests()` (retry policy for unprocessed requests).
| Field | Type | Description |
|---|---|---|
| `maxUnprocessedRequestsRetries` | `int` | Max retries for requests the API reports unprocessed (default from the client; clamped to ≥ 0). |
| `minDelayBetweenUnprocessedRequestsRetriesMillis` | `int` | Minimum delay between those retries, in milliseconds (clamped to ≥ 0). |

## Logs

### `LogOptions`
For `log()->get()` / `log()->stream()`.
| Field | Type | Description |
|---|---|---|
| `raw` | `?bool` | Return the unprocessed log content (no platform post-processing). |
| `download` | `?bool` | Set `Content-Disposition` so the log is served as a download. |
