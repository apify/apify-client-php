<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
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
    public function get(): ?Webhook
    {
        $data = $this->ctx->getResource('', new QueryParams());
        return is_array($data) ? new Webhook($data) : null;
    }

    /**
     * Updates the webhook with the given fields and returns the updated object.
     *
     * @param mixed $newFields any JSON-serializable set of fields to update
     */
    public function update(mixed $newFields): Webhook
    {
        return new Webhook($this->ctx->updateResource('', $newFields));
    }

    /** Deletes the webhook. */
    public function delete(): void
    {
        $this->ctx->deleteResource('');
    }

    /** Dispatches the webhook immediately and returns the resulting dispatch. */
    public function test(): WebhookDispatch
    {
        return new WebhookDispatch($this->ctx->postWithBody('test', new QueryParams(), null, ''));
    }

    /** A client for this webhook's dispatch collection. */
    public function dispatches(): WebhookDispatchCollectionClient
    {
        return new WebhookDispatchCollectionClient($this->http, $this->ctx->subUrl(''), 'dispatches');
    }
}
