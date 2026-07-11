# Models reference

Response models live under the `Apify\Client\Model\` namespace and wrap the JSON objects the API
returns. Each model exposes the commonly-used fields as typed getters; every model also extends
`ApifyResource`, so two extra methods are always available:

- `toArray(): array` — the full raw object as an associative array (nothing the API returns is lost,
  even fields without a dedicated getter).
- `get(string $key): mixed` — a single raw field by its API name.

Getters return `null` when the field is absent from the API response. The `RequestQueueRequest`
model is also used as an input object and therefore additionally exposes setters and a constructor
(see [Input models](#input-models)).

## Resource models

### `Actor`
| Getter | Description |
|---|---|
| `getId(): ?string` | The Actor ID. |
| `getUserId(): ?string` | ID of the user who owns the Actor. |
| `getName(): ?string` | The Actor's technical name. |
| `getUsername(): ?string` | Username of the Actor's owner. |
| `getTitle(): ?string` | Human-readable title. |
| `getDescription(): ?string` | Free-text description. |
| `isPublic(): ?bool` | Whether the Actor is public. |
| `getCreatedAt(): ?string` | ISO-8601 creation timestamp. |
| `getModifiedAt(): ?string` | ISO-8601 last-modification timestamp. |

### `ActorRun`
| Getter | Description |
|---|---|
| `getId(): ?string` | The run ID. |
| `getActId(): ?string` | ID of the Actor that was run. |
| `getActorTaskId(): ?string` | ID of the task the run started from (if any). |
| `getUserId(): ?string` | ID of the user who started the run. |
| `getStatus(): ?string` | Run status (e.g. `RUNNING`, `SUCCEEDED`, `FAILED`). |
| `getStatusMessage(): ?string` | Latest human-readable status message. |
| `getStartedAt(): ?string` | ISO-8601 start timestamp. |
| `getFinishedAt(): ?string` | ISO-8601 finish timestamp (`null` while running). |
| `getBuildId(): ?string` | ID of the build the run used. |
| `getDefaultDatasetId(): ?string` | ID of the run's default dataset. |
| `getDefaultKeyValueStoreId(): ?string` | ID of the run's default key-value store. |
| `getDefaultRequestQueueId(): ?string` | ID of the run's default request queue. |
| `getContainerUrl(): ?string` | The run container's live URL. |
| `isTerminal(): bool` | Whether the run has reached a terminal status. |

### `Build`
| Getter | Description |
|---|---|
| `getId(): ?string` | The build ID. |
| `getActId(): ?string` | ID of the Actor that was built. |
| `getStatus(): ?string` | Build status. |
| `getStartedAt(): ?string` | ISO-8601 start timestamp. |
| `getFinishedAt(): ?string` | ISO-8601 finish timestamp (`null` while building). |
| `getBuildNumber(): ?string` | The resulting build number. |
| `isTerminal(): bool` | Whether the build has reached a terminal status. |

### `ActorVersion`
| Getter | Description |
|---|---|
| `getVersionNumber(): ?string` | The version number (e.g. `0.0`). |
| `getSourceType(): ?string` | Source type (e.g. `SOURCE_FILES`, `GIT_REPO`). |

### `Task`
| Getter | Description |
|---|---|
| `getId(): ?string` | The task ID. |
| `getActId(): ?string` | ID of the Actor the task runs. |
| `getUserId(): ?string` | ID of the task owner. |
| `getName(): ?string` | The task's technical name. |
| `getTitle(): ?string` | Human-readable title. |
| `getCreatedAt(): ?string` | ISO-8601 creation timestamp. |
| `getModifiedAt(): ?string` | ISO-8601 last-modification timestamp. |

### `Schedule`
| Getter | Description |
|---|---|
| `getId(): ?string` | The schedule ID. |
| `getUserId(): ?string` | ID of the schedule owner. |
| `getName(): ?string` | The schedule's name. |
| `getCronExpression(): ?string` | The cron expression that triggers the schedule. |
| `isEnabled(): ?bool` | Whether the schedule is enabled. |

### `Webhook`
| Getter | Description |
|---|---|
| `getId(): ?string` | The webhook ID. |
| `getUserId(): ?string` | ID of the webhook owner. |
| `getRequestUrl(): ?string` | URL the webhook posts to. |
| `getEventTypes(): array` | Event types that trigger the webhook. |

### `WebhookDispatch`
| Getter | Description |
|---|---|
| `getId(): ?string` | The dispatch ID. |
| `getWebhookId(): ?string` | ID of the webhook that was dispatched. |

### `User`
| Getter | Description |
|---|---|
| `getId(): ?string` | The user ID. |
| `getUsername(): ?string` | The username. |

Private account details (plan, email, etc.) returned by `me()->get()` are available via `toArray()`.

### `ActorStoreListItem`
Returned when listing/iterating the Apify Store.
| Getter | Description |
|---|---|
| `getId(): ?string` | The Actor ID. |
| `getName(): ?string` | The Actor's technical name. |
| `getUsername(): ?string` | Username of the Actor's owner. |
| `getTitle(): ?string` | Human-readable title. |

## Storage models

### `Dataset`
| Getter | Description |
|---|---|
| `getId(): ?string` | The dataset ID. |
| `getName(): ?string` | The dataset name (`null` for unnamed datasets). |
| `getUserId(): ?string` | ID of the owner. |
| `getCreatedAt(): ?string` | ISO-8601 creation timestamp. |
| `getModifiedAt(): ?string` | ISO-8601 last-modification timestamp. |
| `getItemCount(): ?int` | Number of items stored. |

### `KeyValueStore`
| Getter | Description |
|---|---|
| `getId(): ?string` | The store ID. |
| `getName(): ?string` | The store name (`null` for unnamed stores). |
| `getUserId(): ?string` | ID of the owner. |
| `getCreatedAt(): ?string` | ISO-8601 creation timestamp. |
| `getModifiedAt(): ?string` | ISO-8601 last-modification timestamp. |

### `KeyValueStoreRecord`
| Getter | Description |
|---|---|
| `getKey(): string` | The record key. |
| `getValue(): string` | The raw record value, as a string (decode it yourself when it is JSON). |
| `getContentType(): ?string` | The record's content type. |

### `KeyValueStoreKey`
| Getter | Description |
|---|---|
| `getKey(): string` | The key name. |
| `getSize(): ?int` | The value size in bytes. |

### `KeyValueStoreKeysPage`
One page returned by `listKeys()`.
| Getter | Description |
|---|---|
| `getItems(): array` | The `KeyValueStoreKey` items on this page. |
| `getLimit(): ?int` | The page size limit. |
| `isTruncated(): bool` | Whether more keys exist beyond this page. |
| `getExclusiveStartKey(): ?string` | The exclusive start key used for this page. |
| `getNextExclusiveStartKey(): ?string` | The start key to request the next page. |

### `RequestQueue`
| Getter | Description |
|---|---|
| `getId(): ?string` | The queue ID. |
| `getName(): ?string` | The queue name (`null` for unnamed queues). |
| `getUserId(): ?string` | ID of the owner. |
| `getCreatedAt(): ?string` | ISO-8601 creation timestamp. |
| `getModifiedAt(): ?string` | ISO-8601 last-modification timestamp. |
| `getTotalRequestCount(): ?int` | Total number of requests ever added. |

### `RequestQueueHead`
Returned by `listHead()` / `listAndLockHead()`.
| Getter | Description |
|---|---|
| `getItems(): array` | The `RequestQueueRequest` items at the head of the queue. |
| `getLimit(): ?int` | The requested head size limit. |

### `RequestQueueOperationInfo`
Returned by single-request add/update operations.
| Getter | Description |
|---|---|
| `getRequestId(): ?string` | ID assigned to the request. |
| `getUniqueKey(): ?string` | The request's unique key. |

### `BatchAddResult`
Returned by `batchAddRequests()`.
| Getter | Description |
|---|---|
| `getProcessedRequests(): array` | Requests the API accepted (as `RequestQueueOperationInfo`). |
| `getUnprocessedRequests(): array` | Requests that could not be processed. |

## Iteration and pagination

### `PaginationList`
Returned by every `list()` method. Implements `IteratorAggregate` and `Countable`, so it can be used
directly in `foreach` / `count()`.
| Getter | Description |
|---|---|
| `getItems(): array` | The items on this page. |
| `getTotal(): ?int` | Total number of matching items across all pages. |
| `getOffset(): ?int` | The offset this page started at. |
| `getLimit(): ?int` | The page size limit. |
| `getCount(): int` | The number of items on this page. |
| `isDesc(): bool` | Whether the listing is newest-first. |
| `getIterator(): Traversable` | Iterator over the items (used by `foreach`). |

## Input models

Some models double as input objects for create/update calls; they expose fluent setters in addition
to their getters.

### `RequestQueueRequest`
Constructor: `new RequestQueueRequest(?string $url = null, ?string $uniqueKey = null, array $data = [])`.
Pass `url` and `uniqueKey` positionally for the common case; `data` seeds any additional raw fields.

| Method | Description |
|---|---|
| `getId(): ?string` / `setId(string): self` | The request ID (assigned by the API on add). |
| `getUrl(): ?string` / `setUrl(string): self` | The request URL. |
| `getUniqueKey(): ?string` / `setUniqueKey(string): self` | The deduplication key. |
| `getMethod(): ?string` / `setMethod(string): self` | The HTTP method (defaults to `GET`). |
| `getUserData(): mixed` / `setUserData(array): self` | Arbitrary user data attached to the request. |

### `ActorEnvVar`
Constructor: `new ActorEnvVar(?string $name = null, ?string $value = null, ?bool $isSecret = null, array $data = [])`.

| Getter | Description |
|---|---|
| `getName(): ?string` | The environment variable name. |
| `getValue(): ?string` | The environment variable value. |
| `getIsSecret(): ?bool` | Whether the value is stored as a secret. |
