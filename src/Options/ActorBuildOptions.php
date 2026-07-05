<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/** Configures building an Actor version. */
final class ActorBuildOptions
{
    public function __construct(
        /** If {@code true}, use beta versions of Apify packages. */
        public readonly ?bool $betaPackages = null,
        /** The tag to apply to the build (e.g. {@code "latest"}). */
        public readonly ?string $tag = null,
        /** Whether to use the Docker build cache (default true). */
        public readonly ?bool $useCache = null,
        /** Maximum seconds to wait server-side for the build (max 60). */
        public readonly ?int $waitForFinish = null,
    ) {
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addBool('betaPackages', $this->betaPackages)
            ->addString('tag', $this->tag)
            ->addBool('useCache', $this->useCache)
            ->addInt('waitForFinish', $this->waitForFinish);
    }
}
