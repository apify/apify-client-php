<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

/**
 * Derives the short, lowercase OS platform token used in the {@code User-Agent} header.
 *
 * The Apify clients agreed to report the operating system using the same short identifiers that
 * Node's {@code os.platform()} yields (e.g. {@code linux}, {@code darwin}, {@code win32}), so a
 * server-side parser sees one consistent value across every client. PHP's {@code PHP_OS} instead
 * reports uname-style names ({@code Linux}, {@code Darwin}, {@code WINNT}, ...), so we translate
 * the ones that differ and lowercase the rest.
 *
 * @internal
 */
final class Platform
{
    /**
     * Translates a {@code PHP_OS}-style value into the short lowercase platform token used in the
     * User-Agent header. Pass {@code PHP_OS} in production; the argument exists so the mapping can be
     * unit-tested for every platform without depending on the host it runs on.
     *
     * Only two families need translating; everything else (Linux, Darwin, FreeBSD, OpenBSD, NetBSD,
     * SunOS, ...) already matches Node's token once lowercased:
     * - Windows: PHP reports {@code WINNT} (or {@code Windows}), Node reports {@code win32}.
     * - Cygwin: PHP reports {@code CYGWIN_NT-10.0-...}, Node reports {@code cygwin}.
     */
    public static function osToken(string $phpOs): string
    {
        $upper = strtoupper($phpOs);
        if (str_starts_with($upper, 'WIN')) {
            return 'win32';
        }
        if (str_starts_with($upper, 'CYGWIN')) {
            return 'cygwin';
        }
        return strtolower($phpOs);
    }

    private function __construct()
    {
    }
}
