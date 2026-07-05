<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** A dataset stores structured results from Actor runs. */
final class Dataset extends ApifyResource
{
    /** The unique dataset ID. */
    public function getId(): ?string
    {
        return $this->getString('id');
    }

    /** The dataset name (empty for unnamed datasets). */
    public function getName(): ?string
    {
        return $this->getString('name');
    }

    /** The ID of the user who owns the dataset. */
    public function getUserId(): ?string
    {
        return $this->getString('userId');
    }

    /** When the dataset was created (ISO-8601 string). */
    public function getCreatedAt(): ?string
    {
        return $this->getString('createdAt');
    }

    /** When the dataset was last modified (ISO-8601 string). */
    public function getModifiedAt(): ?string
    {
        return $this->getString('modifiedAt');
    }

    /** The number of items currently stored. */
    public function getItemCount(): ?int
    {
        return $this->getInt('itemCount');
    }
}
