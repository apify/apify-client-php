<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\Webhook;
use Apify\Client\Model\WebhookDispatch;

/** A client for a specific webhook ({@code /v2/webhooks/{webhookId}}). */
final class WebhookClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(private HttpClientCore $http, string $baseUrl, string $id)
    {
        $this->ctx = ResourceContext::single($http, $baseUrl, 'webhooks', $id);
    }

    /** Fetches the webhook, or {@code null} if it does not exist. */
    public function get(int|float|string|null $timeoutSecs = null): ?Webhook
    {
        $data = $this->ctx->getResource('', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? new Webhook($data) : null;
    }

    /**
     * Updates the webhook with the given fields and returns the updated object.
     *
     * @param mixed $newFields any JSON-serializable set of fields to update
     */
    public function update(mixed $newFields, int|float|string|null $timeoutSecs = null): Webhook
    {
        return new Webhook($this->ctx->updateResource('', $newFields, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)));
    }

    /** Deletes the webhook. */
    public function delete(int|float|string|null $timeoutSecs = null): void
    {
        $this->ctx->deleteResource('', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
    }

    /** Dispatches the webhook immediately and returns the resulting dispatch. */
    public function test(int|float|string|null $timeoutSecs = null): WebhookDispatch
    {
        return new WebhookDispatch(
            $this->ctx->postWithBody('test', new QueryParams(), null, '', $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_MEDIUM))
        );
    }

    /** A client for this webhook's dispatch collection. */
    public function dispatches(): WebhookDispatchCollectionClient
    {
        return new WebhookDispatchCollectionClient($this->http, $this->ctx->subUrl(''), 'dispatches');
    }
}
