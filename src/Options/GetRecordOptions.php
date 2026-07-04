<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/** Configures fetching a key-value-store record. */
final class GetRecordOptions
{
    public function __construct(
        /**
         * Controls the {@code Content-Disposition: attachment} behaviour. Defaults to {@code true},
         * matching the reference client, which always requests the record as an attachment so the API
         * returns the raw bytes directly. Pass {@code null} to omit the parameter entirely.
         */
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
