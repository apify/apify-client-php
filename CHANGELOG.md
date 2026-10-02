# Changelog

## 0.8.0

- Added `InvalidRequestException` (400), `UnauthorizedException` (401), `ForbiddenException` (403),
  `NotFoundException` (404), `ConflictException` (409) and `RateLimitException` (429), plus
  `ServerException` for any 5xx, all extending the existing `ApifyApiException`. The client now
  throws the subclass matching the response's HTTP status, so a `catch` can branch on `instanceof`
  instead of comparing status codes; any other status still throws a plain `ApifyApiException`,
  matching the reference client's `ApifyApiError` subclasses.
- A 404 is now swallowed (resolving to `null`/no-op) regardless of its machine-readable `type`,
  matching the reference client. Previously only `record-not-found`, `record-or-token-not-found`,
  and every HEAD request were swallowed; a 404 with any other `type` incorrectly propagated as an
  error.
- `get()`/`delete()` on a resource client obtained without an ID — `RunClient::dataset()`,
  `keyValueStore()`, `requestQueue()`, `log()`, and `BuildClient::log()` — now throw instead of
  resolving to `null`/no-op on a 404, since the response cannot tell a missing parent (run/build)
  apart from a missing sub-resource. `DatasetClient::getStatistics()`, `TaskClient::getInput()`,
  `ScheduleClient::getLog()`, and `BuildClient::getOpenApiDefinition()` likewise now always throw on
  a 404 instead of returning `null`, since these fixed sub-paths can only 404 because the parent
  resource is gone. `getRecord()`, `getRequest()`, `recordExists()`, and `lastRun()` are unaffected.
- `ActorClient::version()`, `ActorClient::build()`, and `ActorVersionClient::envVar()` now reject an
  empty-string identifier with an `InvalidArgumentException` instead of silently addressing the whole
  collection.
- Fixed `ResourceContext::toSafeId()` (used to build a resource's URL from its id) to replace every
  `/` in the id, not just the first — an id with more than one `/` could previously leave a literal
  `/` in the built URL path.
- Resource ids and URL path segments built from caller input (record keys, request ids) are now
  rejected up front with an `InvalidArgumentException` when empty or `.`/`..`, instead of being
  percent-encoded and sent as-is: a URL parser resolves such dot-segments from the decoded path, so a
  literal `.`/`..` segment can still collapse to a different endpoint even without a `/` in the
  string. Matches the reference client's path-sanitization fix.
- Added timeout tiers: the `ApifyClient` constructor takes optional `timeoutShortSecs`,
  `timeoutMediumSecs`, `timeoutLongSecs`, and `timeoutMaxSecs` options, and the `get()`/`update()`/
  `delete()` methods of every resource client take an optional trailing `$timeoutSecs` (a number of
  seconds, a tier name, or `'noTimeout'`) that overrides the default for that call. Every tier
  defaults to the existing `timeoutSecs`, so a client built without the new options is unaffected.
- Added a `compression` option to the `ApifyClient` constructor: `'brotli'`, `'gzip'`, or a custom
  `HttpCompressorInterface` (new `BrotliHttpCompressor`/`GzipHttpCompressor` implementations let the
  compression quality be configured too). Left unset, the client keeps its existing best-effort
  choice (brotli when available, falling back to gzip).
- Request-body compression is now skipped for a `Content-Type` that already carries its own
  compression (`image/*`, `audio/*`, `video/*`, common archive/office/package formats, web fonts),
  while raw formats under those prefixes (e.g. `image/bmp`, `audio/wav`) and `+json`/`+xml` structured
  suffixes are still compressed, matching the reference client.
- `ApifyClient`'s `$baseUrl`/`$publicBaseUrl` now accept a URL that already ends with the `/v2` API
  version path without doubling it into `.../v2/v2`, matching the reference client.
- `RequestQueueClient::batchAddRequests()` now serializes each request to JSON once up front instead
  of re-encoding it for the size check, the chunk split, and the request body, matching the reference
  client's performance fix. Behavior and chunk boundaries are unchanged.

## 0.7.0

- Bumped `Version::API_SPEC_VERSION` to the Apify OpenAPI spec `v2-2026-10-01T153946Z`.
- Added `Build::getImageDigest()`, exposing the built Docker image's manifest digest (`null` if
  unavailable), per the spec's new `imageDigest` field.
- Removed the stale "contact Apify support to raise these limits" sentence from
  `TaskClient::publish()`'s doc comment and `docs/tasks.md`, matching the spec's updated
  description. Behavior is unchanged.
- `ActorClient::start()`/`call()`/`validateInput()` and `RunClient::metamorph()` now send a `string`
  `$input` as raw bytes instead of JSON-encoding it, so it can be paired with a non-default
  `$options->contentType` (matching the reference client's raw-bytes Actor input support). A
  JSON-serializable array continues to be encoded to JSON as before.
- Added an optional `?DownloadItemsFormat $format` parameter to `DatasetClient::createItemsPublicUrl()`,
  selecting the output format served by the generated URL (defaults to `json`), matching the reference
  client's `createItemsPublicUrl({ format })`.
- Fixed `DatasetClient::iterateItems()` to advance and terminate by the number of rows the API
  scanned (the `X-Apify-Pagination-Count` response header), not by the number of items a page
  returns, falling back to the item count when a response omits the header. This matches the
  reference client exactly: `skipEmpty`/`clean`/`unwind` can make a page's item count land on
  either side of the scanned count, so the previous item-count-based advancement could repeat or
  skip items across pages.

## 0.6.2

- Bumped `Version::API_SPEC_VERSION` to the Apify OpenAPI spec `v2-2026-09-28T115051Z`. No public
  interface changes.
- Updated the `TaskClient::publish()` doc comment and `docs/tasks.md` to reflect the new published-task
  limits (up to 10 published tasks per Actor, 100 per account; previously documented as "fewer than
  50 per Actor"), matching the spec's corrected description. Behavior is unchanged: the client already
  surfaces the API's rejection as-is when a task can't be published.
- The `idempotency-key` header on `RunClient::charge()` is now a required parameter in the spec (was
  optional). No client change needed: this client already always sends an idempotency key, generating
  one automatically when the caller doesn't supply one.
- Documented that the charge idempotency key expires 3 minutes after the charge (a later request
  reusing it creates a new charge rather than deduplicating), per the spec's updated description.
  `RunChargeOptions::$idempotencyKey` and `docs/options.md`.

## 0.6.1

- Bumped `Version::API_SPEC_VERSION` to the Apify OpenAPI spec `v2-2026-09-10T091137Z`. This
  version formally documents the `X-Apify-Pagination-*` response headers (including the
  previously-undocumented `X-Apify-Pagination-Desc`) on all offset-paginated list endpoints and
  the `offset`/`limit`/`desc` query parameters on the webhook dispatches list; this client already
  implemented all of those.
- `DatasetClient::listItems()` now prefers the `X-Apify-Pagination-Desc` response header over the
  requested `desc` option when reporting `PaginationList::isDesc()`, matching the reference JS
  client's `_createPaginationList` and the header newly documented in the spec above.

## 0.6.0

- Synced to Apify OpenAPI spec `v2-2026-09-02T154542Z`.
- Added `Task::getDescription()` getter, exposing the task's human-readable description
  (matching the reference JS client's `Task.description`).

## 0.5.3

- Bumped `Version::API_SPEC_VERSION` to the Apify OpenAPI spec `v2-2026-08-27T071624Z`.
- Bumped `Version::CLIENT_VERSION` to `0.5.3`.

## 0.5.2

- Bumped `Version::API_SPEC_VERSION` to the Apify OpenAPI spec `v2-2026-08-14T072928Z`. This
  version formally adds `Task.isPublic`/`publicConfig` and the `TaskPublicConfig` schema to the
  spec; this client already implemented them in `0.4.0`, ahead of the spec, for parity with the
  reference JS client. No client code or public interface change.

## 0.5.1

- Corrected the write-permission wording on `TaskClient::publish()`/`unpublish()`: the OpenAPI spec
  states that both `isPublic` and `publicConfig` require write permission to the task's Actor, not
  (as `unpublish()`'s docblock previously claimed) permission to the task alone. `publish()`'s
  docblock now also states the Actor's fewer-than-50-published-tasks limit.
- `docs/tasks.md` updated to match.

## 0.5.0

Breaking: `RequestQueueClient` methods that previously returned a raw `array<string,mixed>` (or, for
`batchDeleteRequests`, accepted an untyped `mixed` argument) now use typed models, matching the
OpenAPI-documented response schemas and the reference client's typed result interfaces:

- `listAndLockHead()` now returns `LockedRequestQueueHead` (was `array`).
- `prolongRequestLock()` now returns `RequestLockInfo` (was `array`).
- `unlockRequests()` now returns `UnlockRequestsResult` (was `array`).
- `listRequests()` now returns `RequestQueueRequestsPage` (was `array`).
- `batchDeleteRequests()` now takes `list<RequestQueueRequest>` and returns `BatchDeleteResult` (was
  `mixed $requests` / `array`).
- `RequestQueueHead` and the new `LockedRequestQueueHead` gained the previously-missing
  `getQueueModifiedAt()` getter (the field is present in the OpenAPI spec and the reference client,
  but was not yet exposed by this client).
- `RequestQueueRequest` gained `getRetryCount()`/`getLockExpiresAt()` getters, populated on requests
  returned by `listHead()`/`listAndLockHead()`/`listRequests()`.
- `batchDeleteRequests()` now throws `InvalidArgumentException` up front for an empty or
  over-25-request input, or for any entry missing a non-empty `id`/`uniqueKey`, matching
  `batchAddRequests()`'s and the reference client's validation.
- Integration tests: every "create a resource, then `iterate()` the collection and assert it is
  present" test now retries the iterate-and-check pass with a bounded backoff (via a new
  `IntegrationTestCase::assertEventuallyIterated()` helper) instead of asserting after a single pass.
  Apify's list/pagination endpoints are eventually consistent, and this suite runs concurrently with
  other language clients against the same shared test account, so a resource created immediately
  before an `iterate()` call could occasionally not yet be reflected in the listing; this was causing
  intermittent CI failures unrelated to any client defect. No test's assertion was weakened - each
  still fails if the resource never appears within the timeout.

## 0.4.0

- Synced to Apify OpenAPI spec `v2-2026-08-05T133145Z` (additive nullability/response/description
  changes only; no client code changes required beyond the version constant).
- Added `TaskClient::publish()` and `TaskClient::unpublish()` convenience methods (mirroring the
  reference JS client), plus `Task::isPublic()` and `Task::getPublicConfig()` getters.
- Removed the duplicated "official, but experimental, AI-generated" disclaimer from
  `docs/README.md` and the `ApifyClient` class docblock; it is now stated only once, in the
  top-level `README.md`.

## 0.3.4

- Synced to Apify OpenAPI spec `v2-2026-07-13T092445Z` (adds `402`/`408` error responses to the
  synchronous run and run-resurrect endpoints; relaxes store/run/build/webhook stats counters to
  optional). No public interface or code changes.

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
