<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

/**
 * Normalizes the URL fields of API responses to the serialization the reference client returns
 * (the WHATWG URL parser's output), so the same field compares equal across the sibling clients.
 *
 * It lowercases the scheme and host, punycodes an internationalized host (when the `intl` extension
 * is available), drops a default port, adds `/` for an empty path of a special scheme, resolves
 * `.`/`..` path segments and percent-encodes unsafe characters. Existing percent-escapes, the query
 * values and the fragment are otherwise left alone, so a signed URL survives normalization.
 *
 * @internal
 */
final class UrlNormalizer
{
    /** Special schemes and their default ports, which the WHATWG serialization omits. */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443, 'ftp' => 21];

    /** Characters a path must percent-encode, besides control characters and non-ASCII bytes. */
    private const PATH_UNSAFE = ' "<>`{}#?';

    /** Characters a query must percent-encode, besides control characters and non-ASCII bytes. */
    private const QUERY_UNSAFE = ' "<>#\'';

    /** Characters a fragment must percent-encode, besides control characters and non-ASCII bytes. */
    private const FRAGMENT_UNSAFE = ' "<>`';

    private function __construct()
    {
    }

    /**
     * Returns the normalized form of an absolute URL, or the value unchanged when it is not an
     * absolute URL with a host (there is nothing meaningful to normalize then).
     */
    public static function normalize(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $isSpecial = array_key_exists($scheme, self::DEFAULT_PORTS);
        $result = $scheme . '://';

        if (isset($parts['user'])) {
            $result .= $parts['user'];
            if (isset($parts['pass'])) {
                $result .= ':' . $parts['pass'];
            }
            $result .= '@';
        }

        $result .= self::normalizeHost($parts['host']);

        if (isset($parts['port']) && ($parts['port'] !== (self::DEFAULT_PORTS[$scheme] ?? null))) {
            $result .= ':' . $parts['port'];
        }

        $path = self::resolveDotSegments(self::encode($parts['path'] ?? '', self::PATH_UNSAFE));
        $result .= ($path === '' && $isSpecial) ? '/' : $path;

        if (isset($parts['query'])) {
            $result .= '?' . self::encode($parts['query'], self::QUERY_UNSAFE);
        }
        if (isset($parts['fragment'])) {
            $result .= '#' . self::encode($parts['fragment'], self::FRAGMENT_UNSAFE);
        }

        return $result;
    }

    private static function normalizeHost(string $host): string
    {
        // An IPv6 literal keeps its brackets and is only lowercased.
        if (str_starts_with($host, '[')) {
            return strtolower($host);
        }
        $lowercased = strtolower($host);
        if (preg_match('/[^\x20-\x7e]/', $lowercased) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($lowercased, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                return $ascii;
            }
        }
        return $lowercased;
    }

    /** Percent-encodes control characters, non-ASCII bytes and every character of `$unsafe`. */
    private static function encode(string $component, string $unsafe): string
    {
        $encoded = '';
        foreach (str_split($component) as $char) {
            $code = ord($char);
            $needsEncoding = $code <= 0x20 || $code >= 0x7f || str_contains($unsafe, $char);
            $encoded .= $needsEncoding ? sprintf('%%%02X', $code) : $char;
        }
        return $encoded;
    }

    /** Resolves `.` and `..` segments of an absolute path the way a URL parser does. */
    private static function resolveDotSegments(string $path): string
    {
        if ($path === '' || !str_contains($path, '.')) {
            return $path;
        }
        $output = [];
        $segments = explode('/', $path);
        $lastIndex = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            $lowered = strtolower($segment);
            $isDot = $lowered === '.' || $lowered === '%2e';
            $isDoubleDot = in_array($lowered, ['..', '.%2e', '%2e.', '%2e%2e'], true);
            if ($isDoubleDot) {
                if (count($output) > 1) {
                    array_pop($output);
                }
                if ($index === $lastIndex) {
                    $output[] = '';
                }
            } elseif ($isDot) {
                if ($index === $lastIndex) {
                    $output[] = '';
                }
            } else {
                $output[] = $segment;
            }
        }
        return implode('/', $output);
    }
}
