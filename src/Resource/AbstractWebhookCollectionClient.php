<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\PaginationList;
use Apify\Client\Model\Webhook;
use Apify\Client\Options\ListOptions;
use Generator;

/**
 * The read-only calls of the webhook collections. Both {@see WebhookCollectionClient} and
 * {@see NestedWebhookCollectionClient} list webhooks, and only the first creates them.
 *
 * @internal
 */
abstract class AbstractWebhookCollectionClient
{
    protected ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $baseUrl)
    {
        $this->ctx = ResourceContext::collection($http, $baseUrl, 'webhooks');
    }

    /**
     * Lists webhooks.
     *
     * @return PaginationList<Webhook>
     */
    public function list(?ListOptions $options = null, int|float|string|null $timeoutSecs = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new ListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new Webhook($d), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM));
    }

    /**
     * Lazily yields webhooks. The {@code limit} option caps the total ({@code null} for all) and
     * {@code $chunkSize} sets the page size ({@code null} for the API's default).
     *
     * @return Generator<int,Webhook>
     */
    public function iterate(?ListOptions $options = null, ?int $chunkSize = null, int|float|string|null $timeoutSecs = null): Generator
    {
        $options ??= new ListOptions();
        return ResourceContext::paginateOffset(
            $options->offset ?? 0,
            $options->limit,
            $chunkSize,
            fn (int $offset, ?int $pageLimit) => $this->list($options->withPagination($offset, $pageLimit), $timeoutSecs),
        );
    }
}
