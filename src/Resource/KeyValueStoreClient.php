<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\Signatures;
use Apify\Client\Model\KeyValueStore;
use Apify\Client\Model\KeyValueStoreKey;
use Apify\Client\Model\KeyValueStoreKeysPage;
use Apify\Client\Model\KeyValueStoreRecord;
use Apify\Client\Options\GetRecordOptions;
use Apify\Client\Options\ListKeysOptions;
use Apify\Client\Options\SetRecordOptions;
use Generator;

/** A client for a specific key-value store (and run-nested variants). */
final class KeyValueStoreClient
{
    private function __construct(private ResourceContext $ctx)
    {
    }

    /** @internal */
    public static function forId(HttpClientCore $http, string $baseUrl, string $id): self
    {
        return new self(ResourceContext::single($http, $baseUrl, 'key-value-stores', $id));
    }

    /**
     * Creates a client for a run's default key-value store (nested path only, no ID). Any
     * {@code $inheritedParams} (e.g. the {@code status}/{@code origin} filters pinned by a last-run
     * accessor) become base params so every request resolves the correct run's store. @internal
     */
    public static function nested(HttpClientCore $http, string $base, string $subPath, ?QueryParams $inheritedParams = null): self
    {
        $ctx = ResourceContext::collection($http, $base, $subPath);
        if ($inheritedParams !== null) {
            $ctx->baseParams = $inheritedParams->copy();
        }
        return new self($ctx);
    }

    /** @internal */
    public function withPublicBase(string $publicBaseUrl): self
    {
        $this->ctx->withPublicOrigin($publicBaseUrl);
        return $this;
    }

    /** Fetches the store metadata, or {@code null} if it does not exist. */
    public function get(): ?KeyValueStore
    {
        $data = $this->ctx->getResource('', new QueryParams());
        return is_array($data) ? new KeyValueStore($data) : null;
    }

    /**
     * Updates the store metadata (e.g. name) and returns the updated object.
     *
     * @param mixed $newFields any JSON-serializable set of fields to update
     */
    public function update(mixed $newFields): KeyValueStore
    {
        return new KeyValueStore($this->ctx->updateResource('', $newFields));
    }

    /** Deletes the store. */
    public function delete(): void
    {
        $this->ctx->deleteResource('');
    }

    /** Lists the keys stored in this key-value store. */
    public function listKeys(?ListKeysOptions $options = null): KeyValueStoreKeysPage
    {
        $params = new QueryParams();
        ($options ?? new ListKeysOptions())->appendTo($params);
        return KeyValueStoreKeysPage::fromData($this->ctx->getResourceRequired('keys', $params));
    }

    /**
     * Lazily iterates over the store's keys, transparently following cursor pagination
     * ({@code exclusiveStartKey}/{@code nextExclusiveStartKey}), mirroring the reference client's
     * async-iterable {@code listKeys()}.
     *
     * The options' {@code limit} caps the total number of keys yielded across all pages ({@code null}
     * or {@code 0} = all); {@code exclusiveStartKey} starts the listing after a given key; {@code prefix} and
     * {@code collection} restrict which keys are listed. Unlike the offset/limit collection iterators,
     * there is no separate page-size argument: the per-page size follows the remaining total cap (or
     * the server default when unbounded), exactly as the reference client does.
     *
     * @return Generator<int,KeyValueStoreKey>
     */
    public function iterateKeys(?ListKeysOptions $options = null): Generator
    {
        $options ??= new ListKeysOptions();
        // Total cap across all pages. null or 0 means "iterate the whole store" (the API treats
        // limit=0 as unset). Normalizing 0 -> null here matches the offset paginator's minLimit
        // convention and the sibling clients, and stops a per-page limit=0 from short-circuiting
        // the iteration after a single page.
        $limit = ($options->limit !== null && $options->limit > 0) ? $options->limit : null;
        $exclusiveStartKey = $options->exclusiveStartKey;
        $iterated = 0;

        while (true) {
            // Ask for only as many keys as remain under the total cap (null = server default).
            $remaining = $limit !== null ? $limit - $iterated : null;
            $page = $this->listKeys(new ListKeysOptions(
                limit: $remaining,
                exclusiveStartKey: $exclusiveStartKey,
                prefix: $options->prefix,
                collection: $options->collection,
                signature: $options->signature,
            ));

            $items = $page->getItems();
            if ($items === []) {
                return;
            }
            foreach ($items as $item) {
                yield $item;
            }
            $iterated += count($items);

            $nextKey = $page->getNextExclusiveStartKey();
            if (($limit !== null && $iterated >= $limit) || !$page->isTruncated() || $nextKey === null || $nextKey === '') {
                return;
            }
            $exclusiveStartKey = $nextKey;
        }
    }

    /** Reports whether a record with the given key exists. */
    public function recordExists(string $key): bool
    {
        return $this->ctx->headExists('records/' . ResourceContext::encodePathSegment($key), new QueryParams());
    }

    /**
     * Fetches a record by key, or {@code null} if it does not exist. Like the reference client, it
     * requests the record as an attachment so the API returns the raw bytes directly.
     */
    public function getRecord(string $key, ?GetRecordOptions $options = null): ?KeyValueStoreRecord
    {
        // GetRecordOptions defaults attachment=true (matching the reference client), so a caller-
        // supplied options object requests the record as an attachment unless it opts out explicitly.
        $options ??= new GetRecordOptions();
        $params = new QueryParams();
        $options->appendTo($params);
        $response = $this->ctx->getRaw('records/' . ResourceContext::encodePathSegment($key), $params);
        if ($response === null) {
            return null;
        }
        $contentType = $response->getHeaderLine('Content-Type');
        return new KeyValueStoreRecord($key, (string) $response->getBody(), $contentType === '' ? null : $contentType);
    }

    /**
     * Stores a record with raw bytes and the given content type, honoring the given write options
     * ({@code timeoutSecs}, {@code doNotRetryTimeouts}).
     */
    public function setRecord(string $key, string $value, string $contentType, ?SetRecordOptions $options = null): void
    {
        $options ??= new SetRecordOptions();
        $timeoutSecs = $options->timeoutSecs !== null ? (float) $options->timeoutSecs : null;
        $this->ctx->putRaw(
            'records/' . ResourceContext::encodePathSegment($key),
            new QueryParams(),
            $value,
            $contentType,
            $timeoutSecs,
            $options->doNotRetryTimeouts
        );
    }

    /**
     * Stores a record holding the JSON serialization of {@code $value}.
     *
     * @param mixed $value any JSON-serializable value
     */
    public function setRecordJson(string $key, mixed $value): void
    {
        $this->setRecord($key, Json::encode($value), ResourceContext::CONTENT_TYPE_JSON_CHARSET);
    }

    /** Deletes a record by key. */
    public function deleteRecord(string $key): void
    {
        $this->ctx->deleteResource('records/' . ResourceContext::encodePathSegment($key));
    }

    /**
     * Builds a public URL for fetching the given record. It fetches the store, and if the store
     * exposes a URL-signing secret key (i.e. it is private), appends an HMAC-SHA256 signature so the
     * URL grants access without an API token. The URL is built from the configured public base URL.
     */
    public function getRecordPublicUrl(string $key): string
    {
        $params = new QueryParams();
        $store = $this->get();
        if ($store !== null) {
            $secret = $store->get('urlSigningSecretKey');
            if (is_string($secret)) {
                $params->addString('signature', Signatures::createHmacSignature($secret, $key));
            }
        }
        return $params->applyToUrl($this->ctx->publicUrl('records/' . ResourceContext::encodePathSegment($key)));
    }

    /**
     * Builds a public URL for listing this store's keys, forwarding the given key-listing filters
     * into the URL. As with {@see getRecordPublicUrl()}, a signature is appended for private stores
     * unless the caller already supplied one. {@code $expiresInSecs} optionally bounds a signed URL.
     */
    public function createKeysPublicUrl(?ListKeysOptions $options = null, ?int $expiresInSecs = null): string
    {
        $options ??= new ListKeysOptions();
        $params = new QueryParams();
        $options->appendTo($params);
        if ($options->signature === null) {
            $store = $this->get();
            if ($store !== null) {
                $secret = $store->get('urlSigningSecretKey');
                if (is_string($secret)) {
                    $params->addString(
                        'signature',
                        Signatures::signStorageContent($secret, (string) $store->getId(), $expiresInSecs)
                    );
                }
            }
        }
        return $params->applyToUrl($this->ctx->publicUrl('keys'));
    }
}
