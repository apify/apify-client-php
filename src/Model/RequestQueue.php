<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** A request queue stores URLs to be crawled. */
final class RequestQueue extends ApifyResource
{
    /** The unique queue ID. */
    public function getId(): ?string
    {
        return $this->getString('id');
    }

    /** The queue name (empty for unnamed queues). */
    public function getName(): ?string
    {
        return $this->getString('name');
    }

    /** The ID of the user who owns the queue. */
    public function getUserId(): ?string
    {
        return $this->getString('userId');
    }

    /** When the queue was created (ISO-8601 string). */
    public function getCreatedAt(): ?string
    {
        return $this->getString('createdAt');
    }

    /** When the queue was last modified (ISO-8601 string). */
    public function getModifiedAt(): ?string
    {
        return $this->getString('modifiedAt');
    }

    /** The total number of requests ever added. */
    public function getTotalRequestCount(): ?int
    {
        return $this->getInt('totalRequestCount');
    }
}
