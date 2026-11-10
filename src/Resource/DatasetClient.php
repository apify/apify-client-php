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
     * A dataset client over a ready context, such as the one of the default dataset of a run, which has no ID.
     *
     * @internal
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
     * Fetches the dataset, or returns {@code null} if it does not exist. A client without an ID, such as
     * {@see \Apify\Client\Resource\RunClient::dataset()}, rethrows the 404.
     */
    public function get(int|float|string|null $timeoutSecs = null): ?Dataset
    {
        $data = $this->ctx->getResource('', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? new Dataset($data) : null;
    }

    /**
     * Updates the dataset, for example its name or title.
     *
     * @param mixed $newFields any JSON-serializable fields
     */
    public function update(mixed $newFields, int|float|string|null $timeoutSecs = null): Dataset
    {
        return new Dataset($this->ctx->updateResource('', $newFields, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)));
    }

    /** Deletes the dataset. A missing dataset is ignored, unless the client has no ID. */
    public function delete(int|float|string|null $timeoutSecs = null): void
    {
        $this->ctx->deleteResource('', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
    }

    /**
     * Lists items of the dataset, decoded to PHP values.
     *
     * The endpoint answers with a bare JSON array and reports the pagination in {@code X-Apify-Pagination-*}
     * headers. The page's {@see PaginationList::getCount()} is the number of rows the API scanned, or the
     * number of items returned when that is larger. The next page starts at {@code offset + min(count, limit)}.
     *
     * @return PaginationList<mixed>
     */
    public function listItems(?DatasetListItemsOptions $options = null, int|float|string|null $timeoutSecs = null): PaginationList
    {
        $resolved = $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_LONG);
        return DatasetItemsPage::fetch($this->http, $this->ctx, $options ?? new DatasetListItemsOptions(), $resolved);
    }

    /**
     * Lazily yields the items of the dataset, fetching pages on demand and decoding them like
     * {@see listItems()}.
     *
     * The {@code limit} option caps the total number of items, {@code offset} is the start and
     * {@code $chunkSize} is the page size. Without {@code $chunkSize} and {@code limit}, the first request loads
     * the whole dataset, so pass {@code $chunkSize} for a large one. The other {@see DatasetListItemsOptions}
     * apply to every page.
     *
     * The filters {@code skipEmpty} and {@code clean}, and {@code unwind}, change the number of items per page,
     * so the iterator advances as {@see ResourceContext::paginateOffset()} does: by the page's
     * {@see PaginationList::getCount()}, capped at the limit asked for.
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

    /** Downloads the items in a format (JSON, CSV, XLSX, XML, RSS, or HTML) and returns the bytes. */
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
     * Opens a stream of the items in a format, so a large export can be read in chunks. The stream is not
     * retried.
     *
     * This is a separate method from {@see downloadItems()}, since a PHP method cannot change its return type
     * with an option.
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
     * Pushes items to the dataset.
     *
     * @param mixed $items a JSON object or an array of objects
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
     * Returns statistics of the dataset. A 404 is always thrown, never turned into {@code null}. A {@code null}
     * result means the body is not an object.
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
     * Builds a public URL for downloading the items.
     *
     * Fetches the dataset and, if it has a URL signing secret, signs the URL with HMAC-SHA256, so it works
     * without a token. {@code $expiresInSecs} limits the validity of a signed URL ({@code null} for none). The
     * {@code $format} defaults to {@code json}. {@code $timeoutSecs} is for the request that fetches the
     * dataset (default: the {@code long} tier).
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
        // A signature the caller passed is kept, so the URL does not carry two.
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
