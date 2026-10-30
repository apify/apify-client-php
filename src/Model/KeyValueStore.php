<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** A key-value store holds arbitrary data records. */
final class KeyValueStore extends ApifyResource
{
    /** The unique store ID. */
    public function getId(): ?string
    {
        return $this->getString('id');
    }

    /** The store name (empty for unnamed stores). */
    public function getName(): ?string
    {
        return $this->getString('name');
    }

    /** The ID of the user who owns the store. */
    public function getUserId(): ?string
    {
        return $this->getString('userId');
    }

    /** When the store was created (ISO-8601 string). */
    public function getCreatedAt(): ?string
    {
        return $this->getString('createdAt');
    }

    /** When the store was last modified (ISO-8601 string). */
    public function getModifiedAt(): ?string
    {
        return $this->getString('modifiedAt');
    }

    /** The URL of the store in Apify Console. */
    public function getConsoleUrl(): ?string
    {
        return $this->getNormalizedUrl('consoleUrl');
    }

    /** The public URL of the store keys. */
    public function getKeysPublicUrl(): ?string
    {
        return $this->getNormalizedUrl('keysPublicUrl');
    }

    /** The public URL of the store records. */
    public function getRecordsPublicUrl(): ?string
    {
        return $this->getNormalizedUrl('recordsPublicUrl');
    }
}
