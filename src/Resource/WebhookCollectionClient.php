<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\QueryParams;
use Apify\Client\Model\Webhook;

/**
 * A client for the account-wide webhook collection ({@code GET/POST /v2/webhooks}), supporting both
 * listing and creation. Webhooks nested under an Actor or task are read-only and use
 * {@see NestedWebhookCollectionClient} instead.
 */
final class WebhookCollectionClient extends AbstractWebhookCollectionClient
{
    /**
     * Creates a new webhook.
     *
     * @param mixed $webhook any JSON-serializable webhook definition
     */
    public function create(mixed $webhook): Webhook
    {
        return new Webhook($this->ctx->createResource(new QueryParams(), $webhook));
    }
}
