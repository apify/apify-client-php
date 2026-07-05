<?php

declare(strict_types=1);

namespace Apify\Client\Resource;

use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;
use Apify\Client\Model\User;
use LogicException;

/**
 * A client for accessing user data ({@code /v2/users/{userId}} or {@code /v2/users/me}).
 *
 * For the current user ({@code me}), it also exposes account usage and limits. Those endpoints only
 * exist for {@code me} and throw {@see LogicException} if called on another user's client.
 */
final class UserClient
{
    private const ME = 'me';

    private ResourceContext $ctx;
    private bool $isMe;

    /** @internal */
    public function __construct(private HttpClientCore $http, string $baseUrl, string $id)
    {
        $this->ctx = ResourceContext::single($http, $baseUrl, 'users', $id);
        $this->isMe = $id === self::ME;
    }

    /**
     * Fetches the user. For {@code me} it returns private account details (via {@see User::toArray()});
     * for other users it returns the public profile. Returns {@code null} if the user does not exist.
     */
    public function get(): ?User
    {
        $data = $this->ctx->getResource('', new QueryParams());
        return is_array($data) ? new User($data) : null;
    }

    /**
     * Fetches the current account's monthly usage for the month containing the given date (formatted
     * as {@code YYYY-MM-DD}). An empty/{@code null} date reports the current month. Only available
     * for {@code me}.
     *
     * @return array<string,mixed>
     */
    public function monthlyUsage(?string $date = null): array
    {
        $this->requireMe();
        $params = new QueryParams();
        if ($date !== null && $date !== '') {
            $params->addString('date', $date);
        }
        $data = $this->ctx->getResourceRequired('usage/monthly', $params);
        return is_array($data) ? $data : [];
    }

    /**
     * Fetches the current account's resource limits. Only available for {@code me}.
     *
     * @return array<string,mixed>
     */
    public function limits(): array
    {
        $this->requireMe();
        $data = $this->ctx->getResourceRequired('limits', new QueryParams());
        return is_array($data) ? $data : [];
    }

    /**
     * Updates the current account's resource limits. Only available for {@code me}.
     *
     * @param mixed $newLimits any JSON-serializable limits object
     */
    public function updateLimits(mixed $newLimits): void
    {
        $this->requireMe();
        $this->http->call(
            'PUT',
            $this->ctx->subUrl('limits'),
            Json::encode($newLimits),
            ResourceContext::CONTENT_TYPE_JSON
        );
    }

    private function requireMe(): void
    {
        if (!$this->isMe) {
            throw new LogicException('this operation is only available for the current user (use me())');
        }
    }
}
