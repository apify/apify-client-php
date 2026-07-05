<?php

declare(strict_types=1);

namespace Apify\Client\Options;

use InvalidArgumentException;

/**
 * Configures lazy iteration over a request queue's requests
 * ({@see \Apify\Client\Resource\RequestQueueClient::paginateRequests()}), mirroring the reference
 * client's {@code paginateRequests({ limit, maxPageLimit, exclusiveStartId, cursor, filter })}.
 */
final class PaginateRequestsOptions
{
    /** Default maximum number of requests fetched per page (matches the reference client). */
    public const DEFAULT_MAX_PAGE_LIMIT = 1000;

    /** Filter value: currently locked requests. */
    public const FILTER_LOCKED = 'locked';

    /** Filter value: pending (not-yet-handled) requests. */
    public const FILTER_PENDING = 'pending';

    /**
     * @param list<string>|null $filter restrict the iteration to requests in the given states; each
     *                                  value must be {@see FILTER_LOCKED} or {@see FILTER_PENDING}
     */
    public function __construct(
        /** Maximum total number of requests to iterate across all pages ({@code null} for no bound). */
        public readonly ?int $limit = null,
        /** Maximum number of requests fetched per page (defaults to {@see DEFAULT_MAX_PAGE_LIMIT}). */
        public readonly ?int $maxPageLimit = null,
        /** Start iterating after this request ID (first page only; mutually exclusive with cursor). */
        public readonly ?string $exclusiveStartId = null,
        /** An opaque pagination cursor to start from (mutually exclusive with {@code exclusiveStartId}). */
        public readonly ?string $cursor = null,
        public readonly ?array $filter = null,
    ) {
    }

    /** Validates the options for API-level constraints. @internal */
    public function validate(): void
    {
        if ($this->exclusiveStartId !== null && $this->cursor !== null) {
            throw new InvalidArgumentException(
                'PaginateRequestsOptions: exclusiveStartId and cursor are mutually exclusive'
            );
        }
        if ($this->filter !== null) {
            foreach ($this->filter as $f) {
                if ($f !== self::FILTER_LOCKED && $f !== self::FILTER_PENDING) {
                    throw new InvalidArgumentException(sprintf(
                        'PaginateRequestsOptions: filter entries must be "%s" or "%s", got "%s"',
                        self::FILTER_LOCKED,
                        self::FILTER_PENDING,
                        $f
                    ));
                }
            }
        }
    }
}
