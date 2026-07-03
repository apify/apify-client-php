<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use Apify\Client\Internal\QueryParams;
use InvalidArgumentException;

/** Configures listing a request queue's requests. */
final class ListRequestsOptions
{
    /** Filter value: currently locked requests. */
    public const FILTER_LOCKED = 'locked';

    /** Filter value: pending (not-yet-handled) requests. */
    public const FILTER_PENDING = 'pending';

    /**
     * @param list<string>|null $filter restrict the listing to requests in the given states; each
     *                                  value must be {@see FILTER_LOCKED} or {@see FILTER_PENDING}
     */
    public function __construct(
        /** Maximum number of requests to return. */
        public readonly ?int $limit = null,
        /** List requests after this ID. */
        public readonly ?string $exclusiveStartId = null,
        /** An opaque pagination cursor (alternative to {@code exclusiveStartId}). */
        public readonly ?string $cursor = null,
        public readonly ?array $filter = null,
    ) {
    }

    /** Validates the options for API-level constraints. @internal */
    public function validate(): void
    {
        if ($this->exclusiveStartId !== null && $this->cursor !== null) {
            throw new InvalidArgumentException(
                'ListRequestsOptions: exclusiveStartId and cursor are mutually exclusive'
            );
        }
        if ($this->filter !== null) {
            foreach ($this->filter as $f) {
                if ($f !== self::FILTER_LOCKED && $f !== self::FILTER_PENDING) {
                    throw new InvalidArgumentException(sprintf(
                        'ListRequestsOptions: filter entries must be "%s" or "%s", got "%s"',
                        self::FILTER_LOCKED,
                        self::FILTER_PENDING,
                        $f
                    ));
                }
            }
        }
    }

    /** @internal */
    public function appendTo(QueryParams $q): void
    {
        $q->addInt('limit', $this->limit)
            ->addString('exclusiveStartId', $this->exclusiveStartId)
            ->addString('cursor', $this->cursor)
            ->addCsv('filter', $this->filter !== null ? array_values($this->filter) : null);
    }
}
