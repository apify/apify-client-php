<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\Signatures;
use Apify\Client\Model\KeyValueStore;
use Apify\Client\Model\KeyValueStoreKeysPage;
use Apify\Client\Model\KeyValueStoreRecord;
use Apify\Client\Options\GetRecordOptions;
use Apify\Client\Options\ListKeysOptions;
use Apify\Client\Options\SetRecordOptions;

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

    /** Creates a client for a run's default key-value store (nested path only, no ID). @internal */
    public static function nested(HttpClientCore $http, string $base, string $subPath): self
    {
        return new self(ResourceContext::collection($http, $base, $subPath));
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
        $options ??= new GetRecordOptions(attachment: true);
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
            $secret = DatasetClient::extractString($store->toArray(), 'urlSigningSecretKey');
            if ($secret !== null) {
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
                $secret = DatasetClient::extractString($store->toArray(), 'urlSigningSecretKey');
                if ($secret !== null) {
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
