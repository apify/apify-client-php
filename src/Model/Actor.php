<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** An Actor on the Apify platform. */
final class Actor extends ApifyResource
{
    /** The unique Actor ID. */
    public function getId(): ?string
    {
        return $this->getString('id');
    }

    /** The ID of the user who owns the Actor. */
    public function getUserId(): ?string
    {
        return $this->getString('userId');
    }

    /** The technical name of the Actor (used in API paths). */
    public function getName(): ?string
    {
        return $this->getString('name');
    }

    /** The username of the Actor's owner. */
    public function getUsername(): ?string
    {
        return $this->getString('username');
    }

    /** The human-readable title shown in the UI. */
    public function getTitle(): ?string
    {
        return $this->getString('title');
    }

    /** A description of what the Actor does. */
    public function getDescription(): ?string
    {
        return $this->getString('description');
    }

    /** Whether the Actor is publicly available in Apify Store. */
    public function isPublic(): ?bool
    {
        return $this->getBool('isPublic');
    }

    /** When the Actor was created (ISO-8601 string). */
    public function getCreatedAt(): ?string
    {
        return $this->getString('createdAt');
    }

    /** When the Actor was last modified (ISO-8601 string). */
    public function getModifiedAt(): ?string
    {
        return $this->getString('modifiedAt');
    }
}
