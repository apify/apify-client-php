<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

/**
 * A read-only client for the webhooks of an Actor or task. Create webhooks with {@see WebhookCollectionClient},
 * which targets an Actor or task in the {@code condition} of the webhook.
 */
final class NestedWebhookCollectionClient extends AbstractWebhookCollectionClient
{
}
