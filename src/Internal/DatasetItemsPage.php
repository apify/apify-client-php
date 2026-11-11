<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Model\PaginationList;
use Apify\Client\Options\DatasetListItemsOptions;

/**
 * Reads a page of dataset items for {@see \Apify\Client\Resource\DatasetClient} and
 * {@see \Apify\Client\Resource\RunClient}.
 *
 * @internal
 */
final class DatasetItemsPage
{
    private function __construct()
    {
    }

    /**
     * Fetches a page of items and parses the pagination headers. The {@code count} of the page is the
     * {@code X-Apify-Pagination-Count} scanned rows, or the number of items when that is larger or the header is
     * missing. The header lags a fresh push, so it can be below the items of an unfiltered page.
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
            // The header wins, the requested option applies when it is missing.
            self::headerBool($response->getHeaderLine('X-Apify-Pagination-Desc'), $options->desc ?? false),
        );
    }

    private static function headerBool(string $value, bool $fallback): bool
    {
        return $value === '' ? $fallback : strtolower($value) === 'true';
    }
}
