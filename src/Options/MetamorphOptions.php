<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\ResourceContext;

/** Configures a run metamorph. */
final class MetamorphOptions
{
    public function __construct(
        /** Optionally pins the target Actor's build (unset for default). */
        public readonly ?string $build = null,
        /** The content type of the input body. Defaults to {@code application/json}. */
        public readonly ?string $contentType = null,
    ) {
    }

    /** @internal */
    public function contentTypeOrDefault(): string
    {
        return ($this->contentType !== null && $this->contentType !== '')
            ? $this->contentType
            : ResourceContext::CONTENT_TYPE_JSON;
    }
}
