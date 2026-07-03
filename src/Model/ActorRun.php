<?php

declare(strict_types=1);

namespace Apify\Client\Model;

use Apify\Client\Internal\Statuses;

/** A single execution of an Actor. */
final class ActorRun extends ApifyResource
{
    /** The unique run ID. */
    public function getId(): ?string
    {
        return $this->getString('id');
    }

    /** The ID of the Actor that produced this run. */
    public function getActId(): ?string
    {
        return $this->getString('actId');
    }

    /** The ID of the task that started this run, if any. */
    public function getActorTaskId(): ?string
    {
        return $this->getString('actorTaskId');
    }

    /** The ID of the user who owns the run. */
    public function getUserId(): ?string
    {
        return $this->getString('userId');
    }

    /**
     * The current run status. One of the eight {@code ActorJobStatus} values: {@code READY},
     * {@code RUNNING}, {@code SUCCEEDED}, {@code FAILED}, {@code TIMING-OUT}, {@code TIMED-OUT},
     * {@code ABORTING}, {@code ABORTED}.
     */
    public function getStatus(): ?string
    {
        return $this->getString('status');
    }

    /** An optional human-readable status message. */
    public function getStatusMessage(): ?string
    {
        return $this->getString('statusMessage');
    }

    /** When the run started (ISO-8601 string). */
    public function getStartedAt(): ?string
    {
        return $this->getString('startedAt');
    }

    /** When the run finished (absent while still running). */
    public function getFinishedAt(): ?string
    {
        return $this->getString('finishedAt');
    }

    /** The ID of the build used for the run. */
    public function getBuildId(): ?string
    {
        return $this->getString('buildId');
    }

    /** The ID of the run's default dataset. */
    public function getDefaultDatasetId(): ?string
    {
        return $this->getString('defaultDatasetId');
    }

    /** The ID of the run's default key-value store. */
    public function getDefaultKeyValueStoreId(): ?string
    {
        return $this->getString('defaultKeyValueStoreId');
    }

    /** The ID of the run's default request queue. */
    public function getDefaultRequestQueueId(): ?string
    {
        return $this->getString('defaultRequestQueueId');
    }

    /** The URL of the run's container (for live access). */
    public function getContainerUrl(): ?string
    {
        return $this->getString('containerUrl');
    }

    /** Whether the run has reached a terminal (finished) status. */
    public function isTerminal(): bool
    {
        return Statuses::isTerminal($this->getStatus());
    }
}
