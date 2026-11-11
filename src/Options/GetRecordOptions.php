<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/** Configures fetching a key-value-store record. */
final class GetRecordOptions
{
    public function __construct(
        /** Requests the record as an attachment, so the API returns the raw bytes. {@code null} omits the parameter. */
        public readonly ?bool $attachment = true,
        /** A pre-shared URL signature granting access without an API token. */
        public readonly ?string $signature = null,
    ) {
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addBool('attachment', $this->attachment)->addString('signature', $this->signature);
    }
}
