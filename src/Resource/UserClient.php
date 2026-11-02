<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\User;

/**
 * A client for accessing user data ({@code /v2/users/{userId}} or {@code /v2/users/me}).
 *
 * It also exposes the user's monthly usage and account limits, addressed by the same user ID (or
 * {@code me}).
 */
final class UserClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(private HttpClientCore $http, string $baseUrl, string $id)
    {
        $this->ctx = ResourceContext::single($http, $baseUrl, 'users', $id);
    }

    /**
     * Fetches the user. For {@code me} it returns private account details (via {@see User::toArray()});
     * for other users it returns the public profile. Returns {@code null} if the user does not exist.
     */
    public function get(int|float|string|null $timeoutSecs = null): ?User
    {
        $data = $this->ctx->getResource('', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? new User($data) : null;
    }

    /**
     * Fetches the user's monthly usage for the month containing the given date (formatted as
     * {@code YYYY-MM-DD}). An empty/{@code null} date reports the current month.
     *
     * @return array<string,mixed>
     */
    public function monthlyUsage(?string $date = null, int|float|string|null $timeoutSecs = null): array
    {
        $params = new QueryParams();
        if ($date !== null && $date !== '') {
            $params->addString('date', $date);
        }
        $data = $this->ctx->getResourceRequired('usage/monthly', $params, $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? $data : [];
    }

    /**
     * Fetches the user's resource limits.
     *
     * @return array<string,mixed>
     */
    public function limits(int|float|string|null $timeoutSecs = null): array
    {
        $data = $this->ctx->getResourceRequired('limits', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? $data : [];
    }

    /**
     * Updates the user's resource limits.
     *
     * @param mixed $newLimits any JSON-serializable limits object
     */
    public function updateLimits(mixed $newLimits, int|float|string|null $timeoutSecs = null): void
    {
        $this->http->call(
            'PUT',
            $this->ctx->subUrl('limits'),
            Json::encode($newLimits),
            ResourceContext::CONTENT_TYPE_JSON,
            $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT)
        );
    }
}
