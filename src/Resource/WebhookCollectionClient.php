<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\Webhook;

/** A client for the webhooks of the account, which lists and creates them. */
final class WebhookCollectionClient extends AbstractWebhookCollectionClient
{
    /**
     * Creates a new webhook.
     *
     * @param mixed $webhook any JSON-serializable webhook definition
     */
    public function create(mixed $webhook, int|float|string|null $timeoutSecs = null): Webhook
    {
        return new Webhook($this->ctx->createResource(new QueryParams(), $webhook, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)));
    }
}
