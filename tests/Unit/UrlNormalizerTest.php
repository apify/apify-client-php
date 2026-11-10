<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\Internal\UrlNormalizer;
use Apify\Client\Model\ActorRun;
use Apify\Client\Model\Dataset;
use Apify\Client\Model\KeyValueStore;
use Apify\Client\Model\KeyValueStoreKey;
use Apify\Client\Model\Task;
use Apify\Client\Model\User;
use Apify\Client\Model\Webhook;
use Apify\Client\Model\WebhookDispatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tests of the WHATWG URL normalization of the {@code format: uri} response fields. The expectations are what {@code new URL(x).href} returns. */
final class UrlNormalizerTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function normalizationCases(): iterable
    {
        yield 'already normalized' => ['https://api.apify.com/v2/datasets/abc/items', 'https://api.apify.com/v2/datasets/abc/items'];
        yield 'empty path gets a slash' => ['https://abc.runs.apify.net', 'https://abc.runs.apify.net/'];
        yield 'scheme and host are lowercased, default port dropped' => ['HTTPS://Example.COM:443/Path', 'https://example.com/Path'];
        yield 'default port of http' => ['http://example.com:80/a', 'http://example.com/a'];
        yield 'other port kept' => ['http://example.com:8080', 'http://example.com:8080/'];
        yield 'leading zeros of a port' => ['https://example.com:0080/', 'https://example.com:80/'];
        yield 'dot segments' => ['http://example.com:80/a/./b/../c', 'http://example.com/a/c'];
        yield 'trailing parent segment' => ['https://example.com/a/..', 'https://example.com/'];
        yield 'trailing current segment' => ['https://example.com/a/.', 'https://example.com/a/'];
        yield 'unsafe characters' => [
            'https://example.com/a b?x=a b&y="q"#frag ment',
            'https://example.com/a%20b?x=a%20b&y=%22q%22#frag%20ment',
        ];
        yield 'existing escapes and non-ASCII' => [
            'https://example.com/a%20b/ü?ü=1#ü',
            'https://example.com/a%20b/%C3%BC?%C3%BC=1#%C3%BC',
        ];
        yield 'path characters' => ['https://example.com/{x}|`<>"', 'https://example.com/%7Bx%7D|%60%3C%3E%22'];
        yield 'user info' => ['https://user:p@ss@example.com/x', 'https://user:p%40ss@example.com/x'];
        yield 'user without password' => ['https://user@example.com', 'https://user@example.com/'];
        yield 'ipv6 host' => ['http://[::1]:3000/x', 'http://[::1]:3000/x'];
        yield 'query without path' => ['https://example.com?q=1', 'https://example.com/?q=1'];
        yield 'fragment without path' => ['https://example.com#f', 'https://example.com/#f'];
        yield 'empty segments are kept' => ['https://example.com//a//b', 'https://example.com//a//b'];
        yield 'backslash is a slash' => ['https://example.com/a\\b', 'https://example.com/a/b'];
        yield 'websocket scheme' => ['wss://Example.com:443', 'wss://example.com/'];
    }

    #[DataProvider('normalizationCases')]
    public function testNormalizesLikeTheWhatwgUrlSerialization(string $input, string $expected): void
    {
        self::assertSame($expected, UrlNormalizer::normalize($input));
        // Normalizing is idempotent.
        self::assertSame($expected, UrlNormalizer::normalize($expected));
    }

    public function testInternationalizedHostBecomesPunycode(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('The intl extension is needed to convert an internationalized host to punycode.');
        }
        self::assertSame('https://xn--bcher-kva.example/stra%C3%9Fe', UrlNormalizer::normalize('https://bücher.example/straße'));
    }

    /** @return iterable<string,array{string,string}> */
    public static function internationalizedHostCases(): iterable
    {
        yield 'hyphens' => ["https://AB--c.b\u{00FC}cher.example/x", 'https://ab--c.xn--bcher-kva.example/x'];
        yield 'leading hyphen' => ["https://-a.b\u{00FC}cher.example/x", 'https://-a.xn--bcher-kva.example/x'];
        yield 'empty label' => ["https://a..b\u{00FC}cher.example/x", 'https://a..xn--bcher-kva.example/x'];
        yield 'right-to-left digit label' => ["https://\u{0661}.com/", 'https://xn--9hb.com/'];
        yield 'right-to-left label after an ASCII one' => ["https://\u{05D0}.1a.com/", 'https://xn--4db.1a.com/'];
        yield 'replacement character' => ["https://example.com\u{FFFD}/", "https://example.com\u{FFFD}/"];
        yield 'zero width joiner' => ["https://a\u{200D}b\u{00FC}.com/", "https://a\u{200D}b\u{00FC}.com/"];
    }

    #[DataProvider('internationalizedHostCases')]
    public function testInternationalizedHostsFollowWhatwg(string $input, string $expected): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('The intl extension is needed to convert an internationalized host to punycode.');
        }
        self::assertSame($expected, UrlNormalizer::normalize($input));
    }

    /** @return iterable<string,array{string}> */
    public static function untouchedValues(): iterable
    {
        yield 'relative' => ['/v2/datasets'];
        yield 'no host' => ['https:///'];
        yield 'bad port' => ['https://example.com:port/x'];
        yield 'non-special scheme' => ['mailto:Someone@Example.com'];
        yield 'empty' => [''];
        yield 'encoded slash in host' => ['https://ex%2Fample.com/hook'];
        yield 'encoded space in host' => ['https://exa%20mple.com/'];
        yield 'fullwidth at sign in host' => ["https://evil.com\u{FF20}good.com/hook"];
        yield 'fullwidth slash in host' => ["https://ex\u{FF0F}ample.com/a"];
        yield 'fullwidth percent in host' => ["https://ex\u{FF05}ample.com/a"];
        yield 'text after the IPv6 literal' => ['https://[::1]x/y'];
        yield 'not an IP literal in brackets' => ['https://[nothost]/x'];
        yield 'port out of range' => ['https://example.com:70000/x'];
    }

    #[DataProvider('untouchedValues')]
    public function testLeavesAValueThatIsNotAnAbsoluteWebUrlAsReceived(string $value): void
    {
        self::assertSame($value, UrlNormalizer::normalize($value));
    }

    public function testNormalizeFieldsFollowsPathsAndWildcards(): void
    {
        $data = [
            'a' => 'https://Example.com',
            'nested' => ['b' => 'http://X.org:80', 'untouched' => 'http://X.org:80'],
            'map' => ['one' => 'https://One.example', 'two' => 'https://Two.example:443'],
            'notAString' => 5,
        ];

        $normalized = UrlNormalizer::normalizeFields($data, ['a', 'nested.b', 'map.*', 'notAString', 'missing.key']);

        self::assertSame('https://example.com/', $normalized['a']);
        self::assertSame('http://x.org/', $normalized['nested']['b']);
        self::assertSame('http://X.org:80', $normalized['nested']['untouched']);
        self::assertSame(['one' => 'https://one.example/', 'two' => 'https://two.example/'], $normalized['map']);
        self::assertSame(5, $normalized['notAString']);
    }

    public function testModelsNormalizeTheirUrlFields(): void
    {
        $run = new ActorRun([
            'containerUrl' => 'https://abc.runs.apify.net',
            'consoleUrl' => 'https://console.apify.com/actors/runs/r1',
            'output' => ['results' => 'https://API.apify.com:443/v2/datasets/d1/items'],
        ]);
        self::assertSame('https://abc.runs.apify.net/', $run->getContainerUrl());
        self::assertSame('https://abc.runs.apify.net/', $run->get('containerUrl'));
        self::assertSame('https://api.apify.com/v2/datasets/d1/items', $run->toArray()['output']['results']);

        self::assertSame('https://x.example/', (new Dataset(['itemsPublicUrl' => 'https://X.example']))->get('itemsPublicUrl'));
        self::assertSame('https://x.example/', (new KeyValueStore(['keysPublicUrl' => 'https://X.example']))->get('keysPublicUrl'));
        self::assertSame('https://x.example/', (new KeyValueStore(['recordsPublicUrl' => 'https://X.example']))->get('recordsPublicUrl'));
        self::assertSame('https://x.example/', (new KeyValueStoreKey(['recordPublicUrl' => 'https://X.example']))->get('recordPublicUrl'));
        self::assertSame('https://x.example/', (new Task(['standbyUrl' => 'https://X.example']))->get('standbyUrl'));
        self::assertSame('https://x.example/hook', (new Webhook(['requestUrl' => 'https://X.example:443/hook']))->getRequestUrl());
        self::assertSame(
            'https://x.example/hook',
            (new WebhookDispatch(['webhook' => ['requestUrl' => 'https://X.example/hook']]))->toArray()['webhook']['requestUrl'],
        );
        $user = new User(['profile' => ['pictureUrl' => 'https://X.example', 'websiteUrl' => 'https://Y.example', 'name' => 'Not A URL']]);
        self::assertSame(['https://x.example/', 'https://y.example/', 'Not A URL'], array_values($user->toArray()['profile']));
    }
}
