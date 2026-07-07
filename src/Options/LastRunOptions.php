<?php

declare(strict_types=1);

namespace Apify\Client\Options;

/**
 * Filters which "last" run the last-run accessors resolve to. Leave a field {@code null} to leave
 * that filter unset.
 *
 * {@code origin} is a query parameter declared on the {@code runs/last} endpoints in the OpenAPI
 * spec (alongside {@code status}); it is threaded to that endpoint, matching the reference client's
 * {@code lastRun({ status, origin })}.
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
