<?php

declare(strict_types=1);

namespace Apify\Client\Model;

use Apify\Client\Internal\Statuses;

/** A single build of an Actor. */
final class Build extends ApifyResource
{
    /** The unique build ID. */
    public function getId(): ?string
    {
        return $this->getString('id');
    }

    /** The ID of the Actor this build belongs to. */
    public function getActId(): ?string
    {
        return $this->getString('actId');
    }

    /**
     * The current build status. One of the eight {@code ActorJobStatus} values: {@code READY},
     * {@code RUNNING}, {@code SUCCEEDED}, {@code FAILED}, {@code TIMING-OUT}, {@code TIMED-OUT},
     * {@code ABORTING}, {@code ABORTED}.
     */
    public function getStatus(): ?string
    {
        return $this->getString('status');
    }

    /** When the build started (ISO-8601 string). */
    public function getStartedAt(): ?string
    {
        return $this->getString('startedAt');
    }

    /** When the build finished (absent while still building). */
    public function getFinishedAt(): ?string
    {
        return $this->getString('finishedAt');
    }

    /** The human-readable build number (e.g. {@code "0.1.2"}). */
    public function getBuildNumber(): ?string
    {
        return $this->getString('buildNumber');
    }

    /** Whether the build has reached a terminal (finished) status. */
    public function isTerminal(): bool
    {
        return Statuses::isTerminal($this->getStatus());
    }
}
