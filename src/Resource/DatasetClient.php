<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\DatasetItemsPage;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\Signatures;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\Dataset;
use Apify\Client\Model\PaginationList;
use Apify\Client\Options\DatasetDownloadOptions;
use Apify\Client\Options\DatasetListItemsOptions;
use Apify\Client\Options\DownloadItemsFormat;
use Generator;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/** A client for a specific dataset (and run-nested variants). */
final class DatasetClient
{
    private function __construct(private HttpClientCore $http, private ResourceContext $ctx)
    {
    }

    /** @internal */
    public static function forId(HttpClientCore $http, string $baseUrl, string $id): self
    {
        return new self($http, ResourceContext::single($http, $baseUrl, 'datasets', $id));
    }

    /**
     * Creates a dataset client over a ready context, such as the one of a run's default dataset (nested
     * path only, no ID). @internal
     */
    public static function fromContext(HttpClientCore $http, ResourceContext $ctx): self
    {
        return new self($http, $ctx);
    }

    /** @internal */
    public function withPublicBase(string $publicBaseUrl): self
    {
        $this->ctx->withPublicOrigin($publicBaseUrl);
        return $this;
    }

    /**
     * Fetches the dataset metadata, or {@code null} if it does not exist.
     *
     * When this client was obtained without an ID (e.g. {@see \Apify\Client\Resource\RunClient::dataset()}),
     * a 404 is rethrown instead, since it could mean either the parent resource or the dataset is gone.
     */
    public function get(int|float|string|null $timeoutSecs = null): ?Dataset
    {
        $data = $this->ctx->getResource('', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? new Dataset($data) : null;
    }

    /**
     * Updates the dataset metadata (e.g. name, title) and returns the updated object.
     *
     * @param mixed $newFields any JSON-serializable set of fields to update
     */
    public function update(mixed $newFields, int|float|string|null $timeoutSecs = null): Dataset
    {
        return new Dataset($this->ctx->updateResource('', $newFields, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)));
    }

    /**
     * Deletes the dataset. A not-found is a no-op, unless this client was obtained without an ID (e.g.
     * {@see \Apify\Client\Resource\RunClient::dataset()}), in which case it is rethrown.
     */
    public function delete(int|float|string|null $timeoutSecs = null): void
    {
        $this->ctx->deleteResource('', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
    }

    /**
     * Lists items from the dataset, each decoded to a PHP value (associative array for objects).
     *
     * The dataset items endpoint returns a bare JSON array (not a data envelope) and reports
     * pagination via {@code X-Apify-Pagination-*} headers, surfaced in the returned page. The page's
     * {@see PaginationList::getCount()} is the number of dataset rows the API scanned for it, or the number
     * of items returned when that is larger: filters ({@code clean}, {@code skipEmpty}) can leave it above
     * the number of items. The next page starts at {@code offset + min(count, limit)}; the cap matters only
     * with {@code unwind}, which can return more items than the rows scanned.
     *
     * @return PaginationList<mixed>
     */
    public function listItems(?DatasetListItemsOptions $options = null, int|float|string|null $timeoutSecs = null): PaginationList
    {
        $resolved = $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG);
        return DatasetItemsPage::fetch($this->http, $this->ctx, $options ?? new DatasetListItemsOptions(), $resolved);
    }

    /**
     * Lazily iterates over the dataset's items, fetching pages on demand. Each item is decoded to a
     * PHP value (an associative array for objects), like {@see listItems()}.
     *
     * The options' {@code limit} caps the total number of items yielded across all pages ({@code null}
     * = all), {@code offset} is the starting offset, and {@code $chunkSize} is the per-page size. Dataset
     * items have no default limit, so without {@code $chunkSize} (and without a {@code limit}) the first
     * request loads the whole dataset in one response: pass {@code $chunkSize} to iterate a large
     * dataset. All other {@see DatasetListItemsOptions} fields (field selection, filtering, ordering) are
     * applied to every page.
     *
     * Item-dropping filters ({@code skipEmpty}, and {@code clean} which implies it) and {@code unwind}
     * are applied after {@code offset}/{@code limit}, so a page's item count can land on either side of
     * the number of rows the API scanned to produce it. The iterator therefore pages like every other
     * collection iterator (see {@see ResourceContext::paginateOffset()}): by the page's
     * {@see PaginationList::getCount()}, the scanned rows or the returned items when larger, capped at the
     * page's requested limit, never by the returned item count alone. ({@code skipHidden} only strips
     * hidden fields from each item, not whole items, so it does not affect paging.)
     *
     * @return Generator<int,mixed>
     */
    public function iterateItems(?DatasetListItemsOptions $options = null, ?int $chunkSize = null, int|float|string|null $timeoutSecs = null): Generator
    {
        $options ??= new DatasetListItemsOptions();
        $resolved = $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG);

        return ResourceContext::paginateOffset(
            $options->offset ?? 0,
            $options->limit,
            $chunkSize,
            fn (int $offset, ?int $pageLimit) => DatasetItemsPage::fetch(
                $this->http,
                $this->ctx,
                $options->withPagination($offset, $pageLimit),
                $resolved,
            ),
        );
    }

    /**
     * Downloads dataset items serialized in the given format, returning the raw bytes as a string.
     * Unlike {@see listItems()} (parsed items), this returns the items already serialized to JSON,
     * CSV, XLSX, XML, RSS or HTML — useful for exporting.
     */
    public function downloadItems(
        DownloadItemsFormat $format,
        ?DatasetDownloadOptions $options = null,
        int|float|string|null $timeoutSecs = null,
    ): string {
        $params = new QueryParams();
        $params->addString('format', $format->value);
        ($options ?? new DatasetDownloadOptions())->appendTo($params);
        $url = $this->ctx->mergedParams($params)->applyToUrl($this->ctx->subUrl('items'));
        $response = $this->http->call('GET', $url, timeoutSecs: $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG));
        return (string) $response->getBody();
    }

    /**
     * Opens a live, streaming connection to the dataset's items export in the given format and
     * returns a stream over the serialized bytes, so a large export can be read (and, e.g., piped to a
     * file) in chunks without holding the whole thing in memory.
     *
     * The reference client exposes this as a {@code stream} option of {@code downloadItems()}; this
     * client keeps a separate method instead, since PHP has no equivalent of a single method whose
     * return type switches on a boolean option, and a dedicated {@see streamItems()} name matches this
     * client's existing {@see \Apify\Client\Resource\LogClient::get()}/{@see
     * \Apify\Client\Resource\LogClient::stream()} split for the same buffered/streamed choice.
     *
     * Like {@see \Apify\Client\Resource\LogClient::stream()}, this bypasses the buffered/retrying
     * transport, so because the response is consumed incrementally, it is not retried.
     */
    public function streamItems(
        DownloadItemsFormat $format,
        ?DatasetDownloadOptions $options = null,
    ): StreamInterface {
        $params = new QueryParams();
        $params->addString('format', $format->value);
        ($options ?? new DatasetDownloadOptions())->appendTo($params);
        return $this->ctx->streamRaw('items', $params);
    }

    /**
     * Pushes one or more items to the dataset.
     *
     * @param mixed $items must serialize to a JSON object or an array of objects
     */
    public function pushItems(mixed $items, int|float|string|null $timeoutSecs = null): void
    {
        $url = $this->ctx->mergedParams(new QueryParams())->applyToUrl($this->ctx->subUrl('items'));
        $this->http->call(
            'POST',
            $url,
            Json::encode($items),
            ResourceContext::CONTENT_TYPE_JSON_CHARSET,
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM),
            // A push that timed out may still have been stored, so retrying it could duplicate the items.
            doNotRetryTimeouts: true,
        );
    }

    /**
     * Returns statistical information about the dataset.
     *
     * Unlike {@see get()}, a 404 here is not swallowed: it always throws instead, since the only way
     * this fixed sub-path 404s is the dataset itself being gone (matching the reference client). A
     * {@code null} return means the response body did not decode to an object — not a missing
     * dataset, which throws.
     *
     * @return array<string,mixed>|null
     */
    public function getStatistics(int|float|string|null $timeoutSecs = null): ?array
    {
        $response = $this->ctx->getRawRequired('statistics', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        $decoded = Json::decodeData((string) $response->getBody());
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Builds a public URL for downloading this dataset's items.
     *
     * It fetches the dataset, and if the dataset exposes a URL-signing secret key (i.e. it is
     * private), appends an HMAC-SHA256 signature so the URL grants access without an API token.
     * {@code $expiresInSecs} optionally bounds the validity of a signed URL ({@code null} for
     * non-expiring); it must be positive, otherwise an {@see InvalidArgumentException} is thrown before
     * any request is sent. {@code $format} selects the output format of the items served by the URL
     * (defaults to {@code json} when omitted). The URL is built from the configured public base URL.
     * {@code $timeoutSecs} applies to the request that fetches the dataset (default: the {@code long} tier).
     *
     * @throws InvalidArgumentException if {@code $expiresInSecs} is zero or negative
     */
    public function createItemsPublicUrl(
        ?DatasetListItemsOptions $options = null,
        ?int $expiresInSecs = null,
        ?DownloadItemsFormat $format = null,
        int|float|string|null $timeoutSecs = null,
    ): string {
        Signatures::requirePositiveExpiry($expiresInSecs);
        $options ??= new DatasetListItemsOptions();
        $params = new QueryParams();
        $options->appendTo($params);
        if ($format !== null) {
            $params->addString('format', $format->value);
        }
        // Only compute a signature when the caller did not already supply one, otherwise the URL
        // would carry two conflicting `signature` query params (mirrors KeyValueStoreClient::createKeysPublicUrl).
        if ($options->signature === null) {
            $dataset = $this->get($timeoutSecs ?? TimeoutTiers::TIER_LONG);
            if ($dataset !== null) {
                $secret = $dataset->get('urlSigningSecretKey');
                if (is_string($secret)) {
                    $signature = Signatures::signStorageContent($secret, (string) $dataset->getId(), $expiresInSecs);
                    $params->addString('signature', $signature);
                }
            }
        }
        return $params->applyToUrl($this->ctx->publicUrl('items'));
    }
}
