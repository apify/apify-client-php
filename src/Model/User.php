<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/** A user account. The private details of {@code me}, such as email, plan and proxy settings, are in {@see toArray()}. */
final class User extends ApifyResource
{
    protected const URL_FIELDS = ['profile.pictureUrl', 'profile.websiteUrl'];

    /** The unique user ID. */
    public function getId(): ?string
    {
        return $this->getString('id');
    }

    /** The user's username. */
    public function getUsername(): ?string
    {
        return $this->getString('username');
    }
}
