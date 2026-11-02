<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Model\PaginationList;
use Apify\Client\Options\DatasetListItemsOptions;

/**
 * Reads one page of dataset items together with the number of rows the API scanned to produce it.
 * Shared by {@see \Apify\Client\Resource\DatasetClient} and {@see \Apify\Client\Resource\RunClient}.
 *
 * @internal
 */
final class DatasetItemsPage
{
    private function __construct()
    {
    }

    /**
     * Fetches one page of items and parses the pagination headers. Returns the page together with the
     * {@code X-Apify-Pagination-Count} "scanned rows" count ({@code null} if the response omits the
     * header), which the iterators use to advance.
     *
     * @return array{0:PaginationList<mixed>,1:?int}
     */
    public static function fetch(HttpClientCore $http, ResourceContext $ctx, DatasetListItemsOptions $options, ?float $timeoutSecs = null): array
    {
        $params = new QueryParams();
        $options->appendTo($params);
        $url = $ctx->mergedParams($params)->applyToUrl($ctx->subUrl('items'));
        $response = $http->call('GET', $url, timeoutSecs: $timeoutSecs);

        $items = Json::decode((string) $response->getBody());
        $items = is_array($items) ? array_values($items) : [];
        $count = count($items);

        $page = PaginationList::fromItems(
            $items,
            $response->getHeaderLine('X-Apify-Pagination-Total') === '' ? $count : (int) $response->getHeaderLine('X-Apify-Pagination-Total'),
            $response->getHeaderLine('X-Apify-Pagination-Offset') === '' ? 0 : (int) $response->getHeaderLine('X-Apify-Pagination-Offset'),
            $response->getHeaderLine('X-Apify-Pagination-Limit') === '' ? $count : (int) $response->getHeaderLine('X-Apify-Pagination-Limit'),
            $count,
            // Prefer the server-reported X-Apify-Pagination-Desc header (matches the reference JS
            // client's `_createPaginationList`); fall back to the requested option when the header
            // is absent, e.g. against an older API version that predates it.
            self::headerBool($response->getHeaderLine('X-Apify-Pagination-Desc'), $options->desc ?? false),
        );

        $scannedHeader = $response->getHeaderLine('X-Apify-Pagination-Count');
        $scanned = $scannedHeader === '' ? null : (int) $scannedHeader;

        return [$page, $scanned];
    }

    private static function headerBool(string $value, bool $fallback): bool
    {
        return $value === '' ? $fallback : strtolower($value) === 'true';
    }
}
