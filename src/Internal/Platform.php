<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

/**
 * The OS token of the {@code User-Agent}: the short lowercase name that Node's {@code os.platform()} gives, such as
 * {@code linux}, {@code darwin} or {@code win32}, so the API sees one value across all clients.
 *
 * @internal
 */
final class Platform
{
    /**
     * Translates a {@code PHP_OS} value to the token. Only Windows ({@code win32}) and Cygwin ({@code cygwin})
     * differ from the lowercased value. The argument lets tests cover every platform.
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
