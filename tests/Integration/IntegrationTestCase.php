<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\ApifyClient;
use Apify\Client\Http\HttpCompressorInterface;
use Apify\Client\Options\DatasetListItemsOptions;
use Apify\Client\Resource\DatasetClient;
use PHPUnit\Framework\TestCase;

/**
 * Shared setup of the integration tests. They need an {@code APIFY_TOKEN}. The API URL comes from
 * {@code APIFY_API_URL}, with the {@code /v2} suffix, and defaults to {@code https://api.apify.com/v2}.
 *
 * Tests may run concurrently on the same account, also from other language clients, so each one creates
 * uniquely named resources and cleans them up.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** The integration-test contract fallback base URL. */
    private const DEFAULT_API_URL = 'https://api.apify.com/v2';

    /**
     * Derives the client base URL from {@code APIFY_API_URL}. The client adds {@code /v2} itself, so it is stripped.
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

    /**
     * How many times, and how many seconds apart, a listing is re-read while a resource the test just created is
     * missing from it. The list endpoints read from secondaries that can lag by up to 90 seconds.
     */
    private const LISTING_MAX_ATTEMPTS = 30;
    private const LISTING_RETRY_DELAY_SECS = 3;

    /** Returns a configured client, or skips the test if {@code APIFY_TOKEN} is unset. */
    protected function requireClient(string|HttpCompressorInterface|null $compression = null): ApifyClient
    {
        $token = getenv('APIFY_TOKEN');
        if ($token === false || $token === '') {
            self::markTestSkipped('skipping: APIFY_TOKEN is not set');
        }
        $apiUrl = getenv('APIFY_API_URL');
        return new ApifyClient(
            token: $token,
            baseUrl: self::resolveBaseUrl($apiUrl === false ? null : $apiUrl),
            compression: $compression,
        );
    }

    /** Generates a unique resource name, so that the same test can run in parallel. */
    protected static function uniqueName(string $prefix): string
    {
        return 'php-test-' . $prefix . '-' . bin2hex(random_bytes(6));
    }

    /**
     * Repeats an iterate-and-collect pass ({@code $collect}) until every ID in {@code $expectedIds} was seen,
     * or the attempts run out. The list endpoints are eventually consistent, so a resource that was just
     * created can be missing at first.
     *
     * @param list<string> $expectedIds identifiers that must all be present in a collected pass
     * @param callable(): array<string,bool> $collect performs one pass and returns the set (identifier => true)
     *        of everything it saw
     */
    protected static function assertEventuallyIterated(array $expectedIds, callable $collect, string $label): void
    {
        $seen = [];
        for ($attempt = 1; $attempt <= self::LISTING_MAX_ATTEMPTS; $attempt++) {
            $seen = $collect();
            $missing = array_filter($expectedIds, static fn (string $id): bool => !isset($seen[$id]));
            if ($missing === [] || $attempt === self::LISTING_MAX_ATTEMPTS) {
                break;
            }
            sleep(self::LISTING_RETRY_DELAY_SECS);
        }
        // Always assert (even on a first-attempt success) so the test genuinely records assertions
        // instead of relying on an early return, which PHPUnit flags as a "risky" no-assertion test.
        foreach ($expectedIds as $id) {
            self::assertArrayHasKey($id, $seen, "iterate() did not yield $label $id after retrying");
        }
    }

    /**
     * Waits until the dataset reports at least {@code $expectedItems} items. Its item count is computed
     * asynchronously and lags a fresh push. It re-reads as often as {@see assertEventuallyIterated()}.
     */
    protected static function waitForDatasetItemCount(DatasetClient $dataset, int $expectedItems): void
    {
        for ($attempt = 1; $attempt <= self::LISTING_MAX_ATTEMPTS; $attempt++) {
            if ($dataset->listItems(new DatasetListItemsOptions())->getTotal() >= $expectedItems) {
                return;
            }
            sleep(self::LISTING_RETRY_DELAY_SECS);
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
