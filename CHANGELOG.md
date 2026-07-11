# Changelog

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
