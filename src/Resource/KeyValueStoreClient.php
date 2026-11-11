<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\Signatures;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\KeyValueStore;
use Apify\Client\Model\KeyValueStoreKey;
use Apify\Client\Model\KeyValueStoreKeysPage;
use Apify\Client\Model\KeyValueStoreRecord;
use Apify\Client\Options\GetRecordOptions;
use Apify\Client\Options\ListKeysOptions;
use Apify\Client\Options\SetRecordOptions;
use Generator;
use InvalidArgumentException;

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
     * A client for the default key-value store of a run, which has no ID. The {@code $inheritedParams}, such as
     * the status filter of a last-run accessor, are sent with every request.
     *
     * @internal
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

    /**
     * Fetches the store, or returns {@code null} if it does not exist. A client without an ID, such as
     * {@see \Apify\Client\Resource\RunClient::keyValueStore()}, rethrows the 404.
     */
    public function get(int|float|string|null $timeoutSecs = null): ?KeyValueStore
    {
        $data = $this->ctx->getResource('', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? new KeyValueStore($data) : null;
    }

    /**
     * Updates the store, for example its name.
     *
     * @param mixed $newFields any JSON-serializable fields
     */
    public function update(mixed $newFields, int|float|string|null $timeoutSecs = null): KeyValueStore
    {
        return new KeyValueStore($this->ctx->updateResource('', $newFields, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG)));
    }

    /** Deletes the store. A missing store is ignored, unless the client has no ID. */
    public function delete(int|float|string|null $timeoutSecs = null): void
    {
        $this->ctx->deleteResource('', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
    }

    /** Lists the keys stored in this key-value store. */
    public function listKeys(?ListKeysOptions $options = null, int|float|string|null $timeoutSecs = null): KeyValueStoreKeysPage
    {
        $params = new QueryParams();
        ($options ?? new ListKeysOptions())->appendTo($params);
        return KeyValueStoreKeysPage::fromData(
            $this->ctx->getResourceRequired('keys', $params, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM))
        );
    }

    /**
     * Lazily yields the keys of the store, following the cursor pages.
     *
     * The {@code limit} option caps the total number of keys ({@code null} or {@code 0} for all),
     * {@code exclusiveStartKey} starts the listing after a key, and {@code prefix} and {@code collection} filter
     * the keys. {@code $chunkSize} caps the keys per page ({@code null} for the remaining total, or the API's
     * default without a limit).
     *
     * @return Generator<int,KeyValueStoreKey>
     */
    public function iterateKeys(?ListKeysOptions $options = null, ?int $chunkSize = null, int|float|string|null $timeoutSecs = null): Generator
    {
        $options ??= new ListKeysOptions();
        // A limit of 0 means no limit, as for the API.
        $limit = ($options->limit !== null && $options->limit > 0) ? $options->limit : null;
        $exclusiveStartKey = $options->exclusiveStartKey;
        $iterated = 0;

        while (true) {
            // Only the keys that remain under the total cap, further capped by $chunkSize.
            $remaining = $limit !== null ? $limit - $iterated : null;
            $page = $this->listKeys(new ListKeysOptions(
                limit: ResourceContext::minLimit($remaining, $chunkSize),
                exclusiveStartKey: $exclusiveStartKey,
                prefix: $options->prefix,
                collection: $options->collection,
                signature: $options->signature,
            ), $timeoutSecs);

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
    public function recordExists(string $key, int|float|string|null $timeoutSecs = null): bool
    {
        return $this->ctx->headExists(
            'records/' . ResourceContext::encodePathSegment($key),
            new QueryParams(),
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG)
        );
    }

    /**
     * Fetches a record, or returns {@code null} if it does not exist. The record is requested as an
     * attachment, so the API returns the raw bytes. {@see KeyValueStoreRecord::getValue()} holds them whatever the
     * content type, so decode a JSON record yourself.
     */
    public function getRecord(string $key, ?GetRecordOptions $options = null, int|float|string|null $timeoutSecs = null): ?KeyValueStoreRecord
    {
        $options ??= new GetRecordOptions();
        $params = new QueryParams();
        $options->appendTo($params);
        $response = $this->ctx->getRaw(
            'records/' . ResourceContext::encodePathSegment($key),
            $params,
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG)
        );
        if ($response === null) {
            return null;
        }
        $contentType = $response->getHeaderLine('Content-Type');
        return new KeyValueStoreRecord($key, (string) $response->getBody(), $contentType === '' ? null : $contentType);
    }

    /**
     * Stores a record of raw bytes. {@code $value} is sent as it is, also under {@code application/json}, so it
     * must already be valid JSON. Use {@see setRecordJson()} to have a value encoded.
     *
     * {@code $contentEncoding} is the {@code Content-Encoding} of a {@code $value} that is already compressed,
     * for example {@code gzip}, {@code br}, {@code zstd}, or {@code deflate}. The bytes are then stored as given
     * and not compressed again.
     */
    public function setRecord(
        string $key,
        string $value,
        string $contentType,
        ?SetRecordOptions $options = null,
        ?string $contentEncoding = null,
    ): void {
        $options ??= new SetRecordOptions();
        $timeoutSecs = $this->ctx->resolveTimeout($options->timeoutSecs, TimeoutTiers::TIER_LONG);
        $this->ctx->putRaw(
            'records/' . ResourceContext::encodePathSegment($key),
            new QueryParams(),
            $value,
            $contentType,
            $timeoutSecs,
            $options->doNotRetryTimeouts,
            $contentEncoding !== null ? ['Content-Encoding' => $contentEncoding] : []
        );
    }

    /**
     * Stores the JSON serialization of {@code $value} as a record.
     *
     * @param mixed $value any JSON-serializable value
     */
    public function setRecordJson(string $key, mixed $value, ?SetRecordOptions $options = null): void
    {
        $this->setRecord($key, Json::encode($value), ResourceContext::CONTENT_TYPE_JSON_CHARSET, $options);
    }

    /** Deletes a record by key. */
    public function deleteRecord(string $key, int|float|string|null $timeoutSecs = null): void
    {
        $this->ctx->deleteResource(
            'records/' . ResourceContext::encodePathSegment($key),
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)
        );
    }

    /**
     * Builds a public URL for a record. If the store has a URL signing secret, the URL is signed with
     * HMAC-SHA256, so it works without a token.
     */
    public function getRecordPublicUrl(string $key, int|float|string|null $timeoutSecs = null): string
    {
        $params = new QueryParams();
        $store = $this->get($timeoutSecs ?? TimeoutTiers::TIER_LONG);
        if ($store !== null) {
            $secret = $store->get('urlSigningSecretKey');
            if (is_string($secret)) {
                $params->addString('signature', Signatures::createHmacSignature($secret, $key));
            }
        }
        return $params->applyToUrl($this->ctx->publicUrl('records/' . ResourceContext::encodePathSegment($key)));
    }

    /**
     * Builds a public URL for listing the keys, with the filters of {@code $options}. As for
     * {@see getRecordPublicUrl()}, the URL of a private store is signed, unless the options carry a signature.
     * {@code $expiresInSecs} limits the validity of a signed URL. {@code $timeoutSecs} is for the request that
     * fetches the store (default: the {@code long} tier).
     *
     * @throws InvalidArgumentException if {@code $expiresInSecs} is zero or negative
     */
    public function createKeysPublicUrl(?ListKeysOptions $options = null, ?int $expiresInSecs = null, int|float|string|null $timeoutSecs = null): string
    {
        Signatures::requirePositiveExpiry($expiresInSecs);
        $options ??= new ListKeysOptions();
        $params = new QueryParams();
        $options->appendTo($params);
        if ($options->signature === null) {
            $store = $this->get($timeoutSecs ?? TimeoutTiers::TIER_LONG);
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
