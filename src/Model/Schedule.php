<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** A schedule automatically starts Actor or task runs at specified times. */
final class Schedule extends ApifyResource
{
    /** The unique schedule ID. */
    public function getId(): ?string
    {
        return $this->getString('id');
    }

    /** The ID of the user who owns the schedule. */
    public function getUserId(): ?string
    {
        return $this->getString('userId');
    }

    /** The schedule name. */
    public function getName(): ?string
    {
        return $this->getString('name');
    }

    /** The cron expression governing when the schedule fires. */
    public function getCronExpression(): ?string
    {
        return $this->getString('cronExpression');
    }

    /** Whether the schedule is currently active. */
    public function isEnabled(): ?bool
    {
        return $this->getBool('isEnabled');
    }
}
