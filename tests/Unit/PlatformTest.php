<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\Internal\Platform;
use PHPUnit\Framework\TestCase;

final class PlatformTest extends TestCase
{
    /**
     * The User-Agent OS token must be the short lowercase identifier used by the other Apify clients
     * (Node's os.platform()), regardless of the uname-style value PHP_OS reports.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function osCases(): array
    {
        return [
            'linux' => ['Linux', 'linux'],
            'macos' => ['Darwin', 'darwin'],
            'windows nt' => ['WINNT', 'win32'],
            'windows word' => ['Windows', 'win32'],
            'win32 literal' => ['WIN32', 'win32'],
            'freebsd' => ['FreeBSD', 'freebsd'],
            'openbsd' => ['OpenBSD', 'openbsd'],
            'netbsd' => ['NetBSD', 'netbsd'],
            'solaris/sunos' => ['SunOS', 'sunos'],
            'aix' => ['AIX', 'aix'],
            'cygwin' => ['CYGWIN_NT-10.0-19045', 'cygwin'],
        ];
    }

    /**
     * @dataProvider osCases
     */
    public function testOsTokenMapping(string $phpOs, string $expected): void
    {
        self::assertSame($expected, Platform::osToken($phpOs));
    }

    public function testTokenIsAlwaysLowercase(): void
    {
        foreach (['Linux', 'Darwin', 'WINNT', 'FreeBSD', 'SunOS'] as $os) {
            $token = Platform::osToken($os);
            self::assertSame(strtolower($token), $token);
        }
    }
}
