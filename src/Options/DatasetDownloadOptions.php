<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/**
 * Adds format-specific options for downloading dataset items on top of the shared item
 * filtering/projection options ({@see DatasetListItemsOptions}).
 */
final class DatasetDownloadOptions
{
    public function __construct(
        /** The shared filtering/projection options. */
        public readonly ?DatasetListItemsOptions $items = null,
        /** Set {@code Content-Disposition: attachment} on the response. */
        public readonly ?bool $attachment = null,
        /** Prepend a UTF-8 BOM (useful for Excel-compatible CSV). */
        public readonly ?bool $bom = null,
        /** The CSV field delimiter (default {@code ","}). */
        public readonly ?string $delimiter = null,
        /** Omit the CSV header row. */
        public readonly ?bool $skipHeaderRow = null,
        /** The name of the root XML element (default {@code "items"}). */
        public readonly ?string $xmlRoot = null,
        /** The name of the per-item XML element (default {@code "item"}). */
        public readonly ?string $xmlRow = null,
        /** The title used for RSS/Atom feed exports. */
        public readonly ?string $feedTitle = null,
        /** The description used for RSS/Atom feed exports. */
        public readonly ?string $feedDescription = null,
    ) {
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        if ($this->items !== null) {
            $this->items->appendTo($q);
        }
        $q->addBool('attachment', $this->attachment)
            ->addBool('bom', $this->bom)
            ->addString('delimiter', $this->delimiter)
            ->addBool('skipHeaderRow', $this->skipHeaderRow)
            ->addString('xmlRoot', $this->xmlRoot)
            ->addString('xmlRow', $this->xmlRow)
            ->addString('feedTitle', $this->feedTitle)
            ->addString('feedDescription', $this->feedDescription);
    }
}
