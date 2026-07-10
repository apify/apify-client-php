<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Platform;
use Apify\Client\Version;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testUserAgentFormat(): void
    {
        $client = new ApifyClient(token: 'test-token', httpClient: new MockTransport(), isAtHomeFn: static fn (): bool => false);
        $ua = $client->getUserAgent();

        $expected = sprintf(
            'ApifyClient/%s (%s; PHP/%s); isAtHome/false',
            Version::CLIENT_VERSION,
            Platform::osToken(PHP_OS),
            PHP_VERSION,
        );
        self::assertSame($expected, $ua);
        // The OS token must be the short lowercase platform identifier (aligned with the other
        // Apify clients / Node's os.platform()), never an upper-cased uname value.
        self::assertMatchesRegularExpression('/^ApifyClient\/\S+ \([a-z0-9]+; PHP\//', $ua);
    }

    public function testUserAgentIsAtHomeTrueAndSuffix(): void
    {
        $client = new ApifyClient(
            token: 't',
            userAgentSuffix: 'my-suffix',
            httpClient: new MockTransport(),
            isAtHomeFn: static fn (): bool => true,
        );
        self::assertStringContainsString('isAtHome/true', $client->getUserAgent());
        self::assertStringEndsWith('; my-suffix', $client->getUserAgent());
    }

    public function testApiBaseUrlAppendsV2(): void
    {
        $client = new ApifyClient(token: 't', baseUrl: 'https://api.example.com/', httpClient: new MockTransport());
        self::assertSame('https://api.example.com/v2', $client->getApiBaseUrl());
    }

    public function testVersionConstants(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Version::CLIENT_VERSION);
        self::assertStringStartsWith('v2-', Version::API_SPEC_VERSION);
    }
}
