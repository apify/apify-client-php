<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/** Configures resurrecting a finished run. */
final class RunResurrectOptions
{
    public function __construct(
        /** The tag or number of the build to resurrect with. */
        public readonly ?string $build = null,
        /** Memory in megabytes to allocate. */
        public readonly ?int $memoryMbytes = null,
        /** The run timeout in seconds. */
        public readonly ?int $timeoutSecs = null,
        /** Maximum number of dataset items to charge (pay-per-result Actors). */
        public readonly ?int $maxItems = null,
        /** Maximum total charge in USD (pay-per-event Actors). */
        public readonly ?float $maxTotalChargeUsd = null,
        /** If {@code true}, restart the run if it fails. */
        public readonly ?bool $restartOnError = null,
    ) {
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addString('build', $this->build)
            ->addInt('memory', $this->memoryMbytes)
            ->addInt('timeout', $this->timeoutSecs)
            ->addInt('maxItems', $this->maxItems)
            ->addFloat('maxTotalChargeUsd', $this->maxTotalChargeUsd)
            ->addBool('restartOnError', $this->restartOnError);
    }
}
