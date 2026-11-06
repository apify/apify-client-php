<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Model\PaginationList;
use Apify\Client\Options\DatasetListItemsOptions;

/**
 * Reads one page of dataset items. The page's count is the number of rows the API scanned to produce
 * it. Shared by {@see \Apify\Client\Resource\DatasetClient} and {@see \Apify\Client\Resource\RunClient}.
 *
 * @internal
 */
final class DatasetItemsPage
{
    private function __construct()
    {
    }

    /**
     * Fetches one page of items and parses the pagination headers. The page's {@code count} is the
     * {@code X-Apify-Pagination-Count} "scanned rows" count, or the number of items returned when that is
     * larger (or when the response omits the header). The header is derived from the dataset's item count,
     * which lags a fresh push, so it can fall below the items returned on an unfiltered page.
     *
     * @return PaginationList<mixed>
     */
    public static function fetch(HttpClientCore $http, ResourceContext $ctx, DatasetListItemsOptions $options, ?float $timeoutSecs = null): PaginationList
    {
        $params = new QueryParams();
        $options->appendTo($params);
        $url = $ctx->mergedParams($params)->applyToUrl($ctx->subUrl('items'));
        $response = $http->call('GET', $url, timeoutSecs: $timeoutSecs);

        $items = Json::decode((string) $response->getBody());
        $items = is_array($items) ? array_values($items) : [];
        $returned = count($items);
        $scannedHeader = $response->getHeaderLine('X-Apify-Pagination-Count');

        return PaginationList::fromItems(
            $items,
            $response->getHeaderLine('X-Apify-Pagination-Total') === '' ? $returned : (int) $response->getHeaderLine('X-Apify-Pagination-Total'),
            $response->getHeaderLine('X-Apify-Pagination-Offset') === '' ? 0 : (int) $response->getHeaderLine('X-Apify-Pagination-Offset'),
            $response->getHeaderLine('X-Apify-Pagination-Limit') === '' ? $returned : (int) $response->getHeaderLine('X-Apify-Pagination-Limit'),
            max($scannedHeader === '' ? 0 : (int) $scannedHeader, $returned),
            // Prefer the server-reported X-Apify-Pagination-Desc header (matches the reference JS
            // client's `_createPaginationList`); fall back to the requested option when the header
            // is absent, e.g. against an older API version that predates it.
            self::headerBool($response->getHeaderLine('X-Apify-Pagination-Desc'), $options->desc ?? false),
        );
    }

    private static function headerBool(string $value, bool $fallback): bool
    {
        return $value === '' ? $fallback : strtolower($value) === 'true';
    }
}
