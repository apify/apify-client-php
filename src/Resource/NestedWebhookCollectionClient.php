<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

/**
 * A read-only client for the webhooks nested under an Actor ({@code GET /v2/actors/{id}/webhooks})
 * or a task ({@code GET /v2/actor-tasks/{id}/webhooks}). These endpoints only support listing;
 * webhooks are created through the account-wide {@see WebhookCollectionClient} (which targets an
 * Actor or task via the webhook's {@code condition}), so {@code create} is intentionally not exposed.
 */
final class NestedWebhookCollectionClient extends AbstractWebhookCollectionClient
{
}
