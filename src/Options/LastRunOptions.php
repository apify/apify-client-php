<?php

declare(strict_types=1);

namespace Apify\Client\Options;

/**
 * Filters which "last" run the last-run accessors resolve to. Leave a field {@code null} to leave
 * that filter unset.
 *
 * {@code origin} is an Apify-platform convenience exposed by the reference client but not documented
 * as a query parameter in the OpenAPI spec; it is included for parity, threaded to the same
 * {@code runs/last} endpoint.
 */
final class LastRunOptions
{
    public function __construct(
        /** Filter by run status (e.g. {@code "SUCCEEDED"}, {@code "FAILED"}, {@code "RUNNING"}). */
        public readonly ?string $status = null,
        /** Filter by how the run was started (e.g. {@code "DEVELOPMENT"}, {@code "WEB"}, {@code "API"}). */
        public readonly ?string $origin = null,
    ) {
    }
}
