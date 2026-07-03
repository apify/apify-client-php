<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** A pre-configured Actor run (an Actor task). */
final class Task extends ApifyResource
{
    /** The unique task ID. */
    public function getId(): ?string
    {
        return $this->getString('id');
    }

    /** The ID of the Actor this task runs. */
    public function getActId(): ?string
    {
        return $this->getString('actId');
    }

    /** The ID of the user who owns the task. */
    public function getUserId(): ?string
    {
        return $this->getString('userId');
    }

    /** The technical name of the task. */
    public function getName(): ?string
    {
        return $this->getString('name');
    }

    /** The human-readable title shown in the UI. */
    public function getTitle(): ?string
    {
        return $this->getString('title');
    }

    /** When the task was created (ISO-8601 string). */
    public function getCreatedAt(): ?string
    {
        return $this->getString('createdAt');
    }

    /** When the task was last modified (ISO-8601 string). */
    public function getModifiedAt(): ?string
    {
        return $this->getString('modifiedAt');
    }
}
