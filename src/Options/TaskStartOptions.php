<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/** Options for starting a task run. Like {@see ActorStartOptions}, without the Actor-only {@code contentType} and {@code forcePermissionLevel}. */
final class TaskStartOptions
{
    /**
     * @param list<mixed>|null $webhooks ad-hoc webhooks, sent base64-encoded
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
        /** If {@code true}, restart the run if it fails. */
        public readonly ?bool $restartOnError = null,
        public readonly ?array $webhooks = null,
        /** Retries the start while the account lacks resources. See {@see ActorStartOptions::$waitForResources}. */
        public readonly bool|int|null $waitForResources = null,
    ) {
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
            ->addString('webhooks', ActorStartOptions::encodeWebhooks($this->webhooks));
    }
}
