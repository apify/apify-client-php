<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;

/**
 * Configures listing or downloading dataset items ({@code GET /v2/datasets/{datasetId}/items}).
 * All fields are optional.
 */
final class DatasetListItemsOptions
{
    /**
     * @param list<string>|null $fields       restrict the output to these fields
     * @param list<string>|null $outputFields positionally rename the selected {@code fields} (requires {@code fields})
     * @param list<string>|null $omit         exclude these fields from the output
     * @param list<string>|null $unwind       expand these fields (each array element becomes a separate item)
     * @param list<string>|null $flatten      flatten these nested fields into dot-notation keys
     */
    public function __construct(
        /** Number of items to skip. */
        public readonly ?int $offset = null,
        /** Maximum number of items to return. */
        public readonly ?int $limit = null,
        /** Return items newest-first. */
        public readonly ?bool $desc = null,
        public readonly ?array $fields = null,
        public readonly ?array $outputFields = null,
        public readonly ?array $omit = null,
        /** Skip empty items. */
        public readonly ?bool $skipEmpty = null,
        /** Skip hidden fields (those starting with {@code "#"}). */
        public readonly ?bool $skipHidden = null,
        /** Return only clean (non-empty, non-hidden) items. */
        public readonly ?bool $clean = null,
        public readonly ?array $unwind = null,
        public readonly ?array $flatten = null,
        /** Select a predefined dataset view for field selection. */
        public readonly ?string $view = null,
        /** Return simplified (flattened, cleaned) items. */
        public readonly ?bool $simplified = null,
        /** Skip items that come from failed pages. */
        public readonly ?bool $skipFailedPages = null,
        /** A pre-shared URL signature granting access without an API token. */
        public readonly ?string $signature = null,
    ) {
    }

    /**
     * Returns a copy of these options with a new {@code offset}/{@code limit}, preserving every other
     * field. Used by {@see \Apify\Client\Resource\DatasetClient::iterateItems()} to request pages.
     */
    public function withPagination(?int $offset, ?int $limit): self
    {
        return new self(
            $offset,
            $limit,
            $this->desc,
            $this->fields,
            $this->outputFields,
            $this->omit,
            $this->skipEmpty,
            $this->skipHidden,
            $this->clean,
            $this->unwind,
            $this->flatten,
            $this->view,
            $this->simplified,
            $this->skipFailedPages,
            $this->signature,
        );
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addInt('offset', $this->offset)
            ->addInt('limit', $this->limit)
            ->addBool('desc', $this->desc)
            ->addCsv('fields', $this->fields)
            ->addCsv('outputFields', $this->outputFields)
            ->addCsv('omit', $this->omit)
            ->addBool('skipEmpty', $this->skipEmpty)
            ->addBool('skipHidden', $this->skipHidden)
            ->addBool('clean', $this->clean)
            ->addCsv('unwind', $this->unwind)
            ->addCsv('flatten', $this->flatten)
            ->addString('view', $this->view)
            ->addBool('simplified', $this->simplified)
            ->addBool('skipFailedPages', $this->skipFailedPages)
            ->addString('signature', $this->signature);
    }
}
