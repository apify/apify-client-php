<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\PaginationList;
use Apify\Client\Model\Webhook;
use Apify\Client\Options\ListOptions;

/**
 * Shared read-only behavior for webhook collections. Both the account-wide collection
 * ({@see WebhookCollectionClient}) and the read-only collections nested under an Actor or task
 * ({@see NestedWebhookCollectionClient}) can list webhooks; only the account-wide collection can
 * create them.
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
    public function list(?ListOptions $options = null): PaginationList
    {
        $params = new QueryParams();
        ($options ?? new ListOptions())->appendTo($params);
        return $this->ctx->listResource('', $params, static fn (array $d) => new Webhook($d));
    }
}
