# Changelog

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
