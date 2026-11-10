<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Internal\TimeoutTiers;
use Apify\Client\Model\User;

/** A client for a user, with the monthly usage and account limits of the user. */
final class UserClient
{
    private ResourceContext $ctx;

    /** @internal */
    public function __construct(private HttpClientCore $http, string $baseUrl, string $id)
    {
        $this->ctx = ResourceContext::single($http, $baseUrl, 'users', $id);
    }

    /**
     * Fetches the user: the private account details for {@code me}, the public profile for others. Returns
     * {@code null} if the user does not exist.
     */
    public function get(int|float|string|null $timeoutSecs = null): ?User
    {
        $data = $this->ctx->getResource('', new QueryParams(), $this->ctx->resolveTimeout($timeoutSecs, TimeoutTiers::TIER_SHORT));
        return is_array($data) ? new User($data) : null;
    }

    /**
     * Fetches the monthly usage for the month of the date ({@code YYYY-MM-DD}), by default the current month.
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
