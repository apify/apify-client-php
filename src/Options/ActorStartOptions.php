<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\Json;
use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;

/**
 * Configures starting an Actor run. All fields are optional.
 */
final class ActorStartOptions
{
    /**
     * @param list<mixed>|null $webhooks ad-hoc webhooks to attach to this run; serialized to
     *                                   base64-encoded JSON as the {@code webhooks} query parameter
     */
    public function __construct(
        /** The tag or number of the build to run (e.g. {@code "latest"}, {@code "0.1.2"}). */
        public readonly ?string $build = null,
        /** Memory in megabytes allocated for the run. */
        public readonly ?int $memoryMbytes = null,
        /** Timeout for the run in seconds (0 means no timeout). */
        public readonly ?int $timeoutSecs = null,
        /** Maximum seconds to wait server-side for the run to finish (max 60). */
        public readonly ?int $waitForFinish = null,
        /** Maximum number of dataset items to charge (pay-per-result Actors). */
        public readonly ?int $maxItems = null,
        /** Maximum total charge in USD (pay-per-event Actors). */
        public readonly ?float $maxTotalChargeUsd = null,
        /** The content type of the input body. Defaults to {@code application/json}. */
        public readonly ?string $contentType = null,
        /** If {@code true}, restart the run if it fails. */
        public readonly ?bool $restartOnError = null,
        /**
         * Override the Actor's permission level for this run ({@code LIMITED_PERMISSIONS}/
         * {@code FULL_PERMISSIONS}).
         */
        public readonly ?string $forcePermissionLevel = null,
        public readonly ?array $webhooks = null,
    ) {
    }

    /** @internal */
    public function contentTypeOrDefault(): string
    {
        return ($this->contentType !== null && $this->contentType !== '')
            ? $this->contentType
            : ResourceContext::CONTENT_TYPE_JSON;
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addString('build', $this->build)
            ->addInt('memory', $this->memoryMbytes)
            ->addInt('timeout', $this->timeoutSecs)
            ->addInt('waitForFinish', $this->waitForFinish)
            ->addInt('maxItems', $this->maxItems)
            ->addFloat('maxTotalChargeUsd', $this->maxTotalChargeUsd)
            ->addBool('restartOnError', $this->restartOnError)
            ->addString('forcePermissionLevel', $this->forcePermissionLevel)
            ->addString('webhooks', self::encodeWebhooks($this->webhooks));
    }

    /**
     * Encodes an ad-hoc webhooks list as base64-encoded JSON, as the API's {@code webhooks} query
     * parameter requires. Returns {@code null} for a {@code null} list. Shared by Actor and task
     * start options.
     *
     * @param list<mixed>|null $webhooks
     * @internal
     */
    public static function encodeWebhooks(?array $webhooks): ?string
    {
        if ($webhooks === null) {
            return null;
        }
        return base64_encode(Json::encode($webhooks));
    }
}
