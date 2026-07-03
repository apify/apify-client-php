<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\PaginationList;
use Apify\Client\Model\WebhookDispatch;
use Apify\Client\Options\ListOptions;

/**
 * A client for a webhook dispatch collection: the account-wide collection ({@code GET
 * /v2/webhook-dispatches}) or dispatches nested under a webhook.
 */
final class WebhookDispatchCollectionClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $baseUrl, string $resourcePath)
    {
        $this->ctx = ResourceContext::collection($http, $baseUrl, $resourcePath);
    }

    /**
     * Lists webhook dispatches.
     *
     * @return PaginationList<WebhookDispatch>
     */
    public function list(?ListOptions $options = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new ListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new WebhookDispatch($d));
    }
}
