<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\Internal\UrlNormalizer;
use Apify\Client\Model\ActorRun;
use Apify\Client\Model\Dataset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Covers the URL-field normalization the reference client applies to every spec-declared URL (#1045). */
final class UrlNormalizerTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function normalizationCases(): iterable
    {
        yield 'empty path gains a slash' => ['https://abc123.runs.apify.net', 'https://abc123.runs.apify.net/'];
        yield 'host is lowercased' => ['https://EXAMPLE.com/Path', 'https://example.com/Path'];
        yield 'default port is dropped' => ['https://example.com:443/path', 'https://example.com/path'];
        yield 'non-default port is kept' => ['https://example.com:8443/path', 'https://example.com:8443/path'];
        yield 'unsafe path character is encoded' => ['https://example.com/a b', 'https://example.com/a%20b'];
        yield 'dot segments are resolved' => ['https://example.com/a/./b/../c', 'https://example.com/a/c'];
        yield 'signed query survives' => [
            'https://g8kd8kbc5ge8.runs.apify.net/Items?signature=a+b%2Fc%3D',
            'https://g8kd8kbc5ge8.runs.apify.net/Items?signature=a+b%2Fc%3D',
        ];
        yield 'already normalized is unchanged' => ['https://api.apify.com/v2/datasets/x', 'https://api.apify.com/v2/datasets/x'];
        yield 'not a URL is unchanged' => ['not a url', 'not a url'];
        yield 'relative reference is unchanged' => ['/just/a/path', '/just/a/path'];
    }

    #[DataProvider('normalizationCases')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, UrlNormalizer::normalize($input));
    }

    public function testInternationalizedHostIsPunycoded(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('The intl extension is required to punycode a host.');
        }
        self::assertSame('https://www.xn--luty-kbb.cz/', UrlNormalizer::normalize('https://www.žluty.cz'));
    }

    public function testModelGettersReturnNormalizedUrls(): void
    {
        $run = new ActorRun(['containerUrl' => 'https://G8KD8KBC5GE8.runs.apify.net:443']);
        self::assertSame('https://g8kd8kbc5ge8.runs.apify.net/', $run->getContainerUrl());

        $dataset = new Dataset(['consoleUrl' => 'https://console.apify.com/storage/datasets/x', 'itemsPublicUrl' => 'https://API.apify.com/v2/datasets/x/items']);
        self::assertSame('https://console.apify.com/storage/datasets/x', $dataset->getConsoleUrl());
        self::assertSame('https://api.apify.com/v2/datasets/x/items', $dataset->getItemsPublicUrl());
        // The raw payload stays untouched.
        self::assertSame('https://API.apify.com/v2/datasets/x/items', $dataset->get('itemsPublicUrl'));
    }

    public function testAbsentUrlIsNull(): void
    {
        self::assertNull((new ActorRun([]))->getContainerUrl());
    }
}
