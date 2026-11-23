<?php

declare(strict_types=1);

namespace Apify\Client\Model;

/**
 * A user account. The private details, such as email, plan, and proxy settings, are in {@see toArray()}. The API returns
 * them for {@code me} and for the user of the token, but not for other users or for a token with limited permissions.
 * For a request authenticated with a proxy password, the API returns only the fields Apify Proxy reads
 * ({@code plan.availableProxyGroups}, {@code effectivePlatformFeatures} and {@code isPaying}), without an ID or a username.
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
