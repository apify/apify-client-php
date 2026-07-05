<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** A single invocation of a webhook. */
final class WebhookDispatch extends ApifyResource
{
    /** The unique dispatch ID. */
    public function getId(): ?string
    {
        return $this->getString('id');
    }

    /** The ID of the webhook that produced this dispatch. */
    public function getWebhookId(): ?string
    {
        return $this->getString('webhookId');
    }
}
