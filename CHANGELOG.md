# Changelog

## 0.3.4

- Synced to Apify OpenAPI spec `v2-2026-07-13T092445Z`. No public interface changes. The spec update
  only adds `402` (payment required) and `408` (request timeout) error responses to the synchronous
  run endpoints and the run-resurrect endpoint, and relaxes several store/run/build/webhook stats
  counters from required to optional; both are already handled by the client's generic error mapping
  and nullable model accessors, so no code change was required beyond the version bump.

## 0.3.3

- Added `failOnEmptyTestSuite="true"` to `phpunit.xml.dist` so a suite matching zero tests fails
  instead of passing green.
- Replaced magic literals with named constants: `HttpClientCore::attemptTimeout()` now scales the
  per-attempt timeout by a dedicated `TIMEOUT_BACKOFF_FACTOR` constant, and `Json::decode()` uses a
  named `MAX_JSON_DEPTH` constant.
- Corrected the `RunClient::get()` and `BuildClient::get()` doc comments to explain that the
  `waitForFinishSecs` value is clamped client-side to the request-timeout budget and additionally
  capped at 60s by the server.
- Reworded the docs namespace table so the `Options` row no longer implies its example list is
  exhaustive.

## 0.3.2

- Fixed `RunClient::metamorph()` to normalize a slash-form `targetActorId` (e.g. `username/actor-name`)
  to the URL-safe `username~actor-name` form before sending it, matching the reference JS client.
- Documented the expected `YYYY-MM-DD` date format for `me()->monthlyUsage()` in the docs.
- Expanded the docs namespace table with the commonly-used option classes so their `use` namespace
  is discoverable.

## 0.3.1

- Fixed `KeyValueStoreClient::iterateKeys()` so a `limit` of `0` (like `null`) iterates the whole
  store instead of stopping after a single page; a positive `limit` still caps the total keys
  yielded across all pages.
- Documented the `bool $forefront` parameter on the request-queue `addRequest`/`updateRequest`/
  `prolongRequestLock`/`deleteRequestLock` methods and the `?bool $gracefully` parameter on
  `run()->abort()`, and added behavior descriptions for `recordExists`, `setRecordJson`,
  `deleteRecord`, `getRecordPublicUrl`, `createKeysPublicUrl`, and `createItemsPublicUrl`.
- Corrected the README error-handling description so the "4xx are thrown" rule notes its exception:
  a 404 on a single-resource fetch returns `null` from `get()` and is a no-op for `delete()`.
- Added the optional `baseUrl` argument to the README configuration snippet and documented that
  `paginateRequests()` yields `RequestQueueRequest` instances.
- Fixed `RequestQueueClient::paginateRequests()` so a `limit` of `0` (like `null`) iterates all
  requests instead of yielding a single page, matching `iterateKeys` and the offset paginator.
- Documented that combining item-dropping dataset filters (`skipEmpty`, and `clean` which implies
  it) with multi-page `iterateItems()` can repeat or skip items, mirroring the reference JS client's
  offset advancement.

## 0.3.0

- Added lazy iteration helpers matching the reference client, which iterates every collection: an
  `iterate()` generator on the Actor, Actor-version, Actor-env-var, build, run, dataset,
  key-value-store, request-queue, schedule, task, webhook (account-wide and nested) and
  webhook-dispatch collections; `DatasetClient::iterateItems()` for dataset items; and
  `KeyValueStoreClient::iterateKeys()` for store keys (cursor-based). Each fetches pages on demand.
- Iteration `limit` semantics: for the offset/limit iterators, the options' `limit` now caps the
  total number of items yielded across all pages (unset = all) and the per-page size is a separate
  `$chunkSize` argument. `StoreCollectionClient::iterate()` follows the same rule (previously its
  `limit` was used as the page size); `StoreListOptions::withOffset()` is replaced by
  `withPagination()`.
- `KeyValueStoreClient::iterateKeys()` follows the store's cursor pagination
  (`exclusiveStartKey`/`nextExclusiveStartKey`) and stops on the total-item cap or an untruncated page.
- Documented every new iteration method with runnable examples and clarified the request-queue
  method list, the key-value-store record snippet, and when `TransportException` surfaces versus
  `ApifyApiException`.

## 0.2.2

- `batchAddRequests` now validates every request's individual payload size up front, before any
  HTTP call, so an oversized request anywhere in a large batch is rejected without POSTing earlier
  chunks (previously later chunks could partially mutate the queue before the error was raised).

## 0.2.1

- Synced to Apify OpenAPI spec `v2-2026-07-10T105921Z`. No public interface changes.

## 0.2.0

- Synced to Apify OpenAPI spec `v2-2026-07-08T143931Z`. No public interface changes.
- Request bodies larger than 1024 bytes are now compressed before being sent, using brotli
  (`Content-Encoding: br`) when the PECL `brotli` extension is available and gzip
  (`Content-Encoding: gzip`) as a fallback. Matches the reference client's request compression.
- The `User-Agent` OS token now reports the short lowercase platform identifier (e.g. `linux`,
  `darwin`, `win32`), matching the reference JS client's `os.platform()` token, instead of the
  upper-cased `PHP_OS_FAMILY` value.
- Both request-compression codecs are now covered by deterministic tests: the brotli path (its
  preference over gzip and its output) and the gzip fallback are each exercised regardless of whether
  the host PHP build has the PECL `brotli` extension loaded. No behavior change.

## 0.1.1

- Synced to Apify OpenAPI spec `v2-2026-07-07T132551Z`. No public interface changes.
- `origin` is now a spec-declared query parameter on the last-run endpoints; corrected the
  `LastRunOptions` doc comment accordingly (behavior unchanged). Kept parity with the reference
  client, which does not expose `waitForFinish` on `lastRun`.

## 0.1.0

- Initial PHP client for the Apify API (spec `v2-2026-07-02T131926Z`).
- Resource clients for Actors, Actor versions and environment variables, builds, runs, datasets,
  key-value stores, request queues, tasks, schedules, webhooks, webhook dispatches, the Apify Store,
  users, and logs.
- Convenience helpers consistent with the JS reference client: `actor()->call()`/`start()`,
  `validateInput()`, `defaultBuild()`, `lastRun()`, run `abort`/`metamorph`/`reboot`/`resurrect`/
  `charge`/`waitForFinish`, dataset `listItems`/`downloadItems`/`pushItems`/public URLs, key-value
  store records and public URLs, request queue batch add with retries, lazy request/store iteration,
  and log streaming. `lastRun(status, origin)` filters propagate to the run's nested dataset,
  key-value store, request queue, and log accessors, so they resolve the same run.
- `batchAddRequests` requires a non-empty `uniqueKey` per request, splits batches by both the 25-request
  count limit and the ~9 MiB payload-size limit, and retries only the requests the API reports
  unprocessed in a successful response (previously it hard-coded an empty unprocessed list and could
  mis-correlate requests). Consistent with the reference client, a failed batch call reports that
  chunk's not-yet-processed requests as unprocessed rather than throwing.
- `datasets()->getOrCreate()` and `keyValueStores()->getOrCreate()` accept an optional `schema`.
- `requestQueue($id, RequestQueueClientOptions)` accepts `clientKey` and `timeoutSecs`; `paginateRequests()`
  accepts `PaginateRequestsOptions` (`limit`, `maxPageLimit`, `exclusiveStartId`, `cursor`, `filter`).
- Replaceable HTTP transport (`HttpClientInterface`) with a default Guzzle implementation and a
  PSR-18 adapter; automatic retries with exponential backoff and jitter, growing per-attempt
  timeouts, and HMAC-SHA256 storage URL signing.
- Public `Version::CLIENT_VERSION` and `Version::API_SPEC_VERSION` constants.
- Integration test suite, documentation with runnable examples, and CI workflows for integration
  tests and publishing.
