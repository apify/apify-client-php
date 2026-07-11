<?php

declare(strict_types=1);

namespace Apify\Client;

/**
 * Public version constants for the Apify PHP client.
 *
 * {@see Version::CLIENT_VERSION} is the semantic version of this library and
 * {@see Version::API_SPEC_VERSION} is the {@code info.version} of the Apify OpenAPI
 * specification this client was generated and verified against.
 */
final class Version
{
    /**
     * The semantic version of this client library (see https://semver.org/).
     * Changes to the public interface other than additive ones are considered breaking changes.
     */
    public const CLIENT_VERSION = '0.3.1';

    /**
     * The version of the Apify OpenAPI specification this client was generated and verified
     * against. Corresponds to the {@code info.version} field of the Apify OpenAPI document.
     */
    public const API_SPEC_VERSION = 'v2-2026-07-10T105921Z';

    private function __construct()
    {
    }
}
