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

    /** The human-readable description of the task. */
    public function getDescription(): ?string
    {
        return $this->getString('description');
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

    /**
     * Whether the task is published on its public landing page. Derived from
     * {@code publicConfig.publishedAt} — use {@see TaskClient::publish()} and
     * {@see TaskClient::unpublish()} to change it.
     */
    public function isPublic(): ?bool
    {
        return $this->getBool('isPublic');
    }

    /**
     * The public-facing display configuration of the task's public landing page, or {@code null}
     * if the task is not published. Contains fields such as {@code publishedAt}, {@code seoTitle}
     * and {@code datasetView}.
     *
     * @return array<string,mixed>|null
     */
    public function getPublicConfig(): ?array
    {
        $value = $this->get('publicConfig');
        return is_array($value) ? $value : null;
    }
}
