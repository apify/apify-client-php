<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\ApifyClient;
use PHPUnit\Framework\TestCase;

/**
 * Shared setup for the integration test suite.
 *
 * All integration tests require a valid {@code APIFY_TOKEN} for the test account. The API base URL
 * is taken from {@code APIFY_API_URL} (which includes the {@code /v2} suffix) and falls back to
 * {@code https://api.apify.com/v2}.
 *
 * Tests are designed to run concurrently — including against the same test account from several
 * language clients at once — so every test creates uniquely-named resources and cleans them up.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** The integration-test contract fallback base URL. */
    private const DEFAULT_API_URL = 'https://api.apify.com/v2';

    /**
     * Derives the client base URL from an optional {@code APIFY_API_URL}. The variable includes the
     * {@code /v2} suffix (per the integration-test contract) and falls back to the default. Since the
     * client appends {@code /v2} itself, the suffix is stripped here.
     */
    protected static function resolveBaseUrl(?string $apiUrl): string
    {
        if ($apiUrl === null || $apiUrl === '') {
            $apiUrl = self::DEFAULT_API_URL;
        }
        $trimmed = rtrim($apiUrl, '/');
        if (str_ends_with($trimmed, '/v2')) {
            $trimmed = substr($trimmed, 0, -strlen('/v2'));
        }
        return $trimmed;
    }

    /** Returns a configured client, or skips the test if {@code APIFY_TOKEN} is unset. */
    protected function requireClient(): ApifyClient
    {
        $token = getenv('APIFY_TOKEN');
        if ($token === false || $token === '') {
            self::markTestSkipped('skipping: APIFY_TOKEN is not set');
        }
        $apiUrl = getenv('APIFY_API_URL');
        return new ApifyClient(
            token: $token,
            baseUrl: self::resolveBaseUrl($apiUrl === false ? null : $apiUrl),
        );
    }

    /**
     * Generates a collision-resistant resource name for test isolation. The random component lets
     * the same test run in parallel (across processes and languages) without clobbering shared state.
     */
    protected static function uniqueName(string $prefix): string
    {
        return 'php-test-' . $prefix . '-' . bin2hex(random_bytes(6));
    }

    /**
     * A minimal Actor definition; the API requires at least one version.
     *
     * @return array<string,mixed>
     */
    protected static function minimalActor(string $name): array
    {
        return [
            'name' => $name,
            'isPublic' => false,
            'description' => 'Integration test actor',
            'versions' => [[
                'versionNumber' => '0.0',
                'sourceType' => 'SOURCE_FILES',
                'buildTag' => 'latest',
                'sourceFiles' => [
                    [
                        'name' => 'Dockerfile',
                        'format' => 'TEXT',
                        'content' => "FROM apify/actor-node:20\nCOPY . ./\nCMD node main.js",
                    ],
                    [
                        'name' => 'main.js',
                        'format' => 'TEXT',
                        'content' => "console.log('hello from php client test');",
                    ],
                ],
            ]],
        ];
    }
}
