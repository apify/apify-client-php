<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/**
 * An Apify user account. Private account details for {@code me} (email, plan, proxy settings, …)
 * are available via {@see toArray()}.
 */
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
