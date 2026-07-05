<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** A webhook notifies an external service when specific events occur. */
final class Webhook extends ApifyResource
{
    /** The unique webhook ID. */
    public function getId(): ?string
    {
        return $this->getString('id');
    }

    /** The ID of the user who owns the webhook. */
    public function getUserId(): ?string
    {
        return $this->getString('userId');
    }

    /** The URL the webhook posts to. */
    public function getRequestUrl(): ?string
    {
        return $this->getString('requestUrl');
    }

    /**
     * The events that trigger the webhook.
     *
     * @return list<string>|null
     */
    public function getEventTypes(): ?array
    {
        return $this->getStringList('eventTypes');
    }
}
