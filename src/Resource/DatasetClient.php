<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\Signatures;
use Apify\Client\Model\Dataset;
use Apify\Client\Model\PaginationList;
use Apify\Client\Options\DatasetDownloadOptions;
use Apify\Client\Options\DatasetListItemsOptions;
use Apify\Client\Options\DownloadItemsFormat;
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

    /** Fetches the dataset metadata, or {@code null} if it does not exist. */
    public function get(): ?Dataset
    {
        $data = $this->ctx->getResource('', new QueryParams());
        return is_array($data) ? new Dataset($data) : null;
    }

    /**
     * Updates the dataset metadata (e.g. name, title) and returns the updated object.
     *
     * @param mixed $newFields any JSON-serializable set of fields to update
     */
    public function update(mixed $newFields): Dataset
    {
        return new Dataset($this->ctx->updateResource('', $newFields));
    }

    /** Deletes the dataset. */
    public function delete(): void
    {
        $this->ctx->deleteResource('');
    }

    /**
     * Lists items from the dataset, each decoded to a PHP value (associative array for objects).
     *
     * The dataset items endpoint returns a bare JSON array (not a data envelope) and reports
     * pagination via {@code X-Apify-Pagination-*} headers, surfaced in the returned page.
     *
     * @return PaginationList<mixed>
     */
    public function listItems(?DatasetListItemsOptions $options = null): PaginationList
    {
        $options ??= new DatasetListItemsOptions();
        $params = new QueryParams();
        $options->appendTo($params);
        $url = $this->ctx->mergedParams($params)->applyToUrl($this->ctx->subUrl('items'));
        $response = $this->http->call('GET', $url);

        $items = Json::decode((string) $response->getBody());
        $items = is_array($items) ? array_values($items) : [];
        $count = count($items);

        return PaginationList::fromItems(
            $items,
            $this->headerInt($response, 'X-Apify-Pagination-Total', $count),
            $this->headerInt($response, 'X-Apify-Pagination-Offset', 0),
            $this->headerInt($response, 'X-Apify-Pagination-Limit', $count),
            $count,
            $options->desc ?? false,
        );
    }

    /**
     * Downloads dataset items serialized in the given format, returning the raw bytes as a string.
     * Unlike {@see listItems()} (parsed items), this returns the items already serialized to JSON,
     * CSV, XLSX, XML, RSS or HTML — useful for exporting.
     */
    public function downloadItems(DownloadItemsFormat $format, ?DatasetDownloadOptions $options = null): string
    {
        $params = new QueryParams();
        $params->addString('format', $format->value);
        ($options ?? new DatasetDownloadOptions())->appendTo($params);
        $url = $this->ctx->mergedParams($params)->applyToUrl($this->ctx->subUrl('items'));
        $response = $this->http->call('GET', $url);
        return (string) $response->getBody();
    }

    /**
     * Pushes one or more items to the dataset.
     *
     * @param mixed $items must serialize to a JSON object or an array of objects
     */
    public function pushItems(mixed $items): void
    {
        $url = $this->ctx->mergedParams(new QueryParams())->applyToUrl($this->ctx->subUrl('items'));
        $this->http->call(
            'POST',
            $url,
            Json::encode($items),
            ResourceContext::CONTENT_TYPE_JSON_CHARSET
        );
    }

    /**
     * Returns statistical information about the dataset, or {@code null} if unavailable.
     *
     * @return array<string,mixed>|null
     */
    public function getStatistics(): ?array
    {
        $response = $this->ctx->getRaw('statistics', new QueryParams());
        if ($response === null) {
            return null;
        }
        $decoded = Json::decodeData((string) $response->getBody());
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Builds a public URL for downloading this dataset's items.
     *
     * It fetches the dataset, and if the dataset exposes a URL-signing secret key (i.e. it is
     * private), appends an HMAC-SHA256 signature so the URL grants access without an API token.
     * {@code $expiresInSecs} optionally bounds the validity of a signed URL ({@code null} for
     * non-expiring). The URL is built from the configured public base URL.
     */
    public function createItemsPublicUrl(?DatasetListItemsOptions $options = null, ?int $expiresInSecs = null): string
    {
        $params = new QueryParams();
        ($options ?? new DatasetListItemsOptions())->appendTo($params);
        $dataset = $this->get();
        if ($dataset !== null) {
            $secret = $dataset->get('urlSigningSecretKey');
            if (is_string($secret)) {
                $signature = Signatures::signStorageContent($secret, (string) $dataset->getId(), $expiresInSecs);
                $params->addString('signature', $signature);
            }
        }
        return $params->applyToUrl($this->ctx->publicUrl('items'));
    }

    private function headerInt(ResponseInterface $response, string $name, int $fallback): int
    {
        $value = $response->getHeaderLine($name);
        return $value === '' ? $fallback : (int) $value;
    }
}
