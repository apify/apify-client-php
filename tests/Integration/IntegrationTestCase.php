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
     * Repeatedly re-runs an iterate()-and-collect pass (via {@code $collect}) until every ID in
     * {@code $expectedIds} has been seen, or a bounded number of attempts with backoff is exhausted.
     *
     * Apify's list/pagination endpoints are eventually consistent, and this suite is designed to run
     * concurrently with other test runs (including other language clients) against the same shared
     * test account; under that load, a resource created immediately before an {@code iterate()} call
     * can take a few seconds to be reflected in a collection listing. Retrying the whole pass (rather
     * than looping forever or giving up after one try) keeps the test genuinely exercising
     * {@code iterate()} while tolerating that lag: it still fails, with the same diagnostic message,
     * if a resource never appears within the bounded timeout.
     *
     * @param list<string> $expectedIds identifiers that must all be present in a collected pass
     * @param callable(): array<string,bool> $collect performs one iterate() pass and returns the set
     *        (map of identifier => true) of everything it saw
     */
    protected static function assertEventuallyIterated(array $expectedIds, callable $collect, string $label): void
    {
        $maxAttempts = 8;
        $delaySecs = 0.5;
        $seen = [];
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $seen = $collect();
            $missing = array_filter($expectedIds, static fn (string $id): bool => !isset($seen[$id]));
            if ($missing === [] || $attempt === $maxAttempts) {
                break;
            }
            usleep((int) ($delaySecs * 1_000_000));
            $delaySecs = min($delaySecs * 1.6, 5.0);
        }
        // Always assert (even on a first-attempt success) so the test genuinely records assertions
        // instead of relying on an early return, which PHPUnit flags as a "risky" no-assertion test.
        foreach ($expectedIds as $id) {
            self::assertArrayHasKey($id, $seen, "iterate() did not yield $label $id after retrying");
        }
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
