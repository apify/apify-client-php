<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;
use Apify\Client\Internal\ResourceContext;

/** Configures Actor input validation. All fields are optional. */
final class ValidateInputOptions
{
    public function __construct(
        /** The tag or number of the build whose input schema is used for validation. */
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

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addString('build', $this->build);
    }
}
