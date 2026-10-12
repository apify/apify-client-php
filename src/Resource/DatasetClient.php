<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

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
use Psr\Http\Message\ResponseInterface;

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
     * Creates a dataset client for a run's default dataset (nested path only, no ID). Any
     * {@code $inheritedParams} (e.g. the {@code status}/{@code origin} filters pinned by a last-run
     * accessor) become base params so every request resolves the correct run's dataset. @internal
     */
    public static function nested(HttpClientCore $http, string $base, string $subPath, ?QueryParams $inheritedParams = null): self
    {
        $ctx = ResourceContext::collection($http, $base, $subPath);
        if ($inheritedParams !== null) {
            $ctx->baseParams = $inheritedParams->copy();
        }
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
     * pagination via {@code X-Apify-Pagination-*} headers, surfaced in the returned page.
     *
     * @return PaginationList<mixed>
     */
    public function listItems(?DatasetListItemsOptions $options = null, int|float|string|null $timeoutSecs = null): PaginationList
    {
        $resolved = $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG);
        return $this->fetchItemsPage($options ?? new DatasetListItemsOptions(), $resolved)[0];
    }

    /**
     * Lazily iterates over the dataset's items, fetching pages on demand. Each item is decoded to a
     * PHP value (an associative array for objects), like {@see listItems()}.
     *
     * The options' {@code limit} caps the total number of items yielded across all pages ({@code null}
     * = all), {@code offset} is the starting offset, and {@code $chunkSize} is the per-page size
     * ({@code null} = the server default). All other {@see DatasetListItemsOptions} fields (field
     * selection, filtering, ordering) are applied to every page.
     *
     * Item-dropping filters ({@code skipEmpty}, and {@code clean} which implies it) and {@code unwind}
     * are applied after {@code offset}/{@code limit}, so a page's item count can land on either side of
     * the number of rows the API actually scanned to produce it. The iterator therefore advances and
     * terminates by the scanned count the API reports in the {@code X-Apify-Pagination-Count} header —
     * never by the returned item count — matching the reference JS client exactly (including its
     * fallback to the item count on a response that omits the header, e.g. behind a proxy that strips
     * it, and its use of whichever of the two is larger, since the header can lag a fresh push).
     * ({@code skipHidden} only strips hidden fields from each item, not whole items, so it does not
     * affect paging.)
     *
     * The scanned count is also capped at what the page's request asked for — see {@see scannedRows()}
     * for why {@code unwind} specifically makes that cap necessary here (unlike the offset/limit
     * collection iterators in {@see \Apify\Client\Internal\ResourceContext::paginateOffset()}, which
     * have no such cap because none of their endpoints has an item-multiplying transform). The
     * listing's reported {@code total} is never consulted for the stop condition, there or here: it can
     * grow while the iteration runs (e.g. a run still pushing to the dataset being read), so with no
     * {@code $limit} the loop always ends with one request that scans nothing, rather than stopping at
     * the size the dataset had when it started.
     *
     * @return Generator<int,mixed>
     */
    public function iterateItems(?DatasetListItemsOptions $options = null, ?int $chunkSize = null, int|float|string|null $timeoutSecs = null): Generator
    {
        $options ??= new DatasetListItemsOptions();
        $startOffset = $options->offset ?? 0;
        $limit = $options->limit;
        $resolved = $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG);

        $firstPageLimit = ResourceContext::minLimit($limit, $chunkSize);
        [$page, $scanned] = $this->fetchItemsPage($options->withPagination($startOffset, $firstPageLimit), $resolved);
        foreach ($page->getItems() as $item) {
            yield $item;
        }

        $pageScanned = self::scannedRows($page, $scanned, $firstPageLimit);
        $currentOffset = $startOffset + $pageScanned;
        // Items still to yield, or null for "unbounded" (no $limit given).
        $remaining = ($limit !== null && $limit > 0) ? $limit - $pageScanned : null;

        // Two distinct ways to stop: a page that scans nothing (the dataset is exhausted), or
        // $remaining having already reached 0 (the caller's $limit is satisfied) — either ends the loop.
        while ($pageScanned > 0 && ($remaining === null || $remaining > 0)) {
            $pageLimit = ResourceContext::minLimit($remaining, $chunkSize);
            [$page, $scanned] = $this->fetchItemsPage($options->withPagination($currentOffset, $pageLimit), $resolved);
            foreach ($page->getItems() as $item) {
                yield $item;
            }
            $pageScanned = self::scannedRows($page, $scanned, $pageLimit);
            $currentOffset += $pageScanned;
            if ($remaining !== null) {
                $remaining -= $pageScanned;
            }
        }
    }

    /**
     * The number of rows a page advances the offset by: the larger of its scanned-count header (if
     * present) and its returned item count, capped at the limit requested for it ({@code null} =
     * uncapped).
     *
     * Unlike {@see \Apify\Client\Internal\ResourceContext}'s plain {@code advancedBy()} (which has no
     * such cap — see its docblock), this one is load-bearing whenever {@code unwind} is set:
     * {@code unwind} turns each scanned row into one item per unwound array element, so the returned
     * item count can legitimately exceed the number of rows the API scanned to produce it (the reverse
     * of {@code skipEmpty}/{@code clean}, which only ever drop rows). The server never scans more rows
     * for a page than that page's requested limit, so the true row advance can never exceed it either;
     * capping the {@code max()} above at the requested limit keeps an unwound page's inflated item count
     * (or, if the scanned-count header is also missing, its unwind-inflated fallback) from overstating
     * the real advance, which would otherwise skip whatever rows lie between the true (smaller) advance
     * and the inflated one.
     *
     * @param PaginationList<mixed> $page
     */
    private static function scannedRows(PaginationList $page, ?int $scannedHeader, ?int $requestedLimit): int
    {
        $scanned = max($scannedHeader ?? 0, count($page->getItems()));
        return $requestedLimit !== null ? min($scanned, $requestedLimit) : $scanned;
    }

    /**
     * Fetches one page of dataset items and parses the pagination headers. Returns the page together
     * with the {@code X-Apify-Pagination-Count} "scanned rows" count ({@code null} if the response
     * omits the header), which {@see iterateItems()} uses to advance — see its doc comment.
     *
     * @return array{0:PaginationList<mixed>,1:?int}
     */
    private function fetchItemsPage(DatasetListItemsOptions $options, ?float $timeoutSecs = null): array
    {
        $params = new QueryParams();
        $options->appendTo($params);
        $url = $this->ctx->mergedParams($params)->applyToUrl($this->ctx->subUrl('items'));
        $response = $this->http->call('GET', $url, timeoutSecs: $timeoutSecs);

        $items = Json::decode((string) $response->getBody());
        $items = is_array($items) ? array_values($items) : [];
        $count = count($items);

        $page = PaginationList::fromItems(
            $items,
            $this->headerInt($response, 'X-Apify-Pagination-Total', $count),
            $this->headerInt($response, 'X-Apify-Pagination-Offset', 0),
            $this->headerInt($response, 'X-Apify-Pagination-Limit', $count),
            $count,
            // Prefer the server-reported X-Apify-Pagination-Desc header (matches the reference JS
            // client's `_createPaginationList`); fall back to the requested option when the header
            // is absent, e.g. against an older API version that predates it.
            $this->headerBool($response, 'X-Apify-Pagination-Desc', $options->desc ?? false),
        );

        $scannedHeader = $response->getHeaderLine('X-Apify-Pagination-Count');
        $scanned = $scannedHeader === '' ? null : (int) $scannedHeader;

        return [$page, $scanned];
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
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG)
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
     * non-expiring). {@code $format} selects the output format of the items served by the URL
     * (defaults to {@code json} when omitted). The URL is built from the configured public base URL.
     */
    public function createItemsPublicUrl(
        ?DatasetListItemsOptions $options = null,
        ?int $expiresInSecs = null,
        ?DownloadItemsFormat $format = null,
    ): string {
        $options ??= new DatasetListItemsOptions();
        $params = new QueryParams();
        $options->appendTo($params);
        if ($format !== null) {
            $params->addString('format', $format->value);
        }
        // Only compute a signature when the caller did not already supply one, otherwise the URL
        // would carry two conflicting `signature` query params (mirrors KeyValueStoreClient::createKeysPublicUrl).
        if ($options->signature === null) {
            $dataset = $this->get();
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

    private function headerInt(ResponseInterface $response, string $name, int $fallback): int
    {
        $value = $response->getHeaderLine($name);
        return $value === '' ? $fallback : (int) $value;
    }

    private function headerBool(ResponseInterface $response, string $name, bool $fallback): bool
    {
        $value = $response->getHeaderLine($name);
        return $value === '' ? $fallback : $value === 'true';
    }
}
