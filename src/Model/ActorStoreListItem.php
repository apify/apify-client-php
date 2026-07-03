<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** An Actor as listed in the Apify Store. */
final class ActorStoreListItem extends ApifyResource
{
    /** The unique Actor ID. */
    public function getId(): ?string
    {
        return $this->getString('id');
    }

    /** The technical name of the Actor. */
    public function getName(): ?string
    {
        return $this->getString('name');
    }

    /** The username of the Actor's owner. */
    public function getUsername(): ?string
    {
        return $this->getString('username');
    }

    /** The human-readable title. */
    public function getTitle(): ?string
    {
        return $this->getString('title');
    }
}
