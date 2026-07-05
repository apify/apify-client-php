<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\WebhookDispatch;

/** A client for a specific webhook dispatch ({@code /v2/webhook-dispatches/{dispatchId}}). */
final class WebhookDispatchClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(HttpClientCore $http, string $baseUrl, string $id)
    {
        $this->ctx = ResourceContext::single($http, $baseUrl, 'webhook-dispatches', $id);
    }

    /** Fetches the dispatch, or {@code null} if it does not exist. */
    public function get(): ?WebhookDispatch
    {
        $data = $this->ctx->getResource('', new QueryParams());
        return is_array($data) ? new WebhookDispatch($data) : null;
    }
}
