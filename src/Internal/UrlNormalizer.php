<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

/**
 * Normalizes the URL fields of API responses to the WHATWG URL serialization, so this client returns
 * the same string for the same field as the reference client (which parses them with {@code new URL()}).
 *
 * For a special-scheme URL ({@code http}, {@code https}, {@code ws}, {@code wss}, {@code ftp}) it
 * lowercases the scheme and host, converts an internationalized host to punycode (when the optional
 * {@code intl} extension is available), drops a default port, resolves dot segments, adds the {@code /}
 * of an empty path, and percent-encodes characters that are unsafe in the path, query and fragment. A
 * value that is not an absolute special-scheme URL is returned unchanged.
 *
 * @internal
 */
final class UrlNormalizer
{
    /** Default ports of the special schemes; a URL spelling its scheme's default port drops it. */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443, 'ftp' => 21];

    /** Characters (besides controls, space and non-ASCII) percent-encoded in a path. */
    private const PATH_UNSAFE = '"#<>?`{}';

    /** Characters (besides controls, space and non-ASCII) percent-encoded in a query. */
    private const QUERY_UNSAFE = '"#<>\'';

    /** Characters (besides controls, space and non-ASCII) percent-encoded in a fragment. */
    private const FRAGMENT_UNSAFE = '"<>`';

    /** Characters (besides controls, space and non-ASCII) percent-encoded in the user name and password. */
    private const USERINFO_UNSAFE = '"#<>?`{}/:;=@[\\]^|';

    /** An IPv6 literal in a host: the brackets around hexadecimal digits, colons and dots. */
    private const IPV6_LITERAL_PATTERN = '/^\[[0-9a-f:.]+\]$/';

    /**
     * The IDNA error bits that WHATWG URL parsing ignores (it turns {@code CheckHyphens} and
     * {@code VerifyDnsLength} off): empty label, label too long, domain too long, leading hyphen,
     * trailing hyphen and hyphens in positions 3 and 4.
     */
    private const IGNORED_IDNA_ERRORS = IDNA_ERROR_EMPTY_LABEL | IDNA_ERROR_LABEL_TOO_LONG | IDNA_ERROR_DOMAIN_NAME_TOO_LONG
        | IDNA_ERROR_LEADING_HYPHEN | IDNA_ERROR_TRAILING_HYPHEN | IDNA_ERROR_HYPHEN_3_4;

    /** The highest valid port number. */
    private const MAX_PORT = 65535;

    /** Code points that may not appear in a decoded host (the WHATWG forbidden domain code points). */
    private const FORBIDDEN_HOST_PATTERN = '/[\x00-\x20#\/:<>?@\[\\\\\]^|%\x7F]/';

    /** The highest code point of the space and control range, which is always percent-encoded. */
    private const LAST_CONTROL_OR_SPACE = 0x20;

    /** The highest printable ASCII code point; DEL and non-ASCII bytes above it are always encoded. */
    private const LAST_PRINTABLE = 0x7E;

    private function __construct()
    {
    }

    /**
     * Returns the normalized form of {@code $url}, or {@code $url} itself when it is not an absolute
     * special-scheme URL.
     */
    public static function normalize(string $url): string
    {
        $cleaned = preg_replace('/[\t\n\r]/', '', trim($url, "\x00..\x20"));
        if ($cleaned === null || preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):(.*)$/s', $cleaned, $m) !== 1) {
            return $url;
        }
        $scheme = strtolower($m[1]);
        if (!isset(self::DEFAULT_PORTS[$scheme])) {
            return $url;
        }

        // A special URL tolerates any number of slashes (or backslashes) before the authority.
        $rest = ltrim($m[2], '/\\');
        $authorityEnd = strcspn($rest, '/\\?#');
        $authority = substr($rest, 0, $authorityEnd);
        $remainder = substr($rest, $authorityEnd);

        $host = self::normalizeAuthority($authority, $scheme);
        if ($host === null) {
            return $url;
        }

        $fragment = '';
        $hashAt = strpos($remainder, '#');
        if ($hashAt !== false) {
            $fragment = '#' . self::encode(substr($remainder, $hashAt + 1), self::FRAGMENT_UNSAFE);
            $remainder = substr($remainder, 0, $hashAt);
        }
        $query = '';
        $queryAt = strpos($remainder, '?');
        if ($queryAt !== false) {
            $query = '?' . self::encode(substr($remainder, $queryAt + 1), self::QUERY_UNSAFE);
            $remainder = substr($remainder, 0, $queryAt);
        }

        return $scheme . '://' . $host . self::normalizePath($remainder) . $query . $fragment;
    }

    /**
     * Normalizes every URL field named by {@code $paths} in a decoded response object. A path is a
     * dot-separated list of keys; the key {@code *} matches every entry of an object (for a map of
     * URLs). Only string values are touched.
     *
     * @param array<string,mixed> $data
     * @param list<string>        $paths
     * @return array<string,mixed>
     */
    public static function normalizeFields(array $data, array $paths): array
    {
        foreach ($paths as $path) {
            $data = self::normalizeNode($data, explode('.', $path));
        }
        return $data;
    }

    /**
     * @param array<string,mixed> $node
     * @param list<string>        $keys
     * @return array<string,mixed>
     */
    private static function normalizeNode(array $node, array $keys): array
    {
        $key = array_shift($keys);
        if ($key === null) {
            return $node;
        }
        $targets = $key === '*' ? array_keys($node) : (array_key_exists($key, $node) ? [$key] : []);
        foreach ($targets as $target) {
            $value = $node[$target];
            if ($keys === []) {
                if (is_string($value)) {
                    $node[$target] = self::normalize($value);
                }
            } elseif (is_array($value)) {
                $node[$target] = self::normalizeNode($value, $keys);
            }
        }
        return $node;
    }

    /** Normalizes {@code [userinfo@]host[:port]}; {@code null} when it has no usable host. */
    private static function normalizeAuthority(string $authority, string $scheme): ?string
    {
        $userinfo = '';
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $credentials = substr($authority, 0, $at);
            $authority = substr($authority, $at + 1);
            $colon = strpos($credentials, ':');
            $user = $colon === false ? $credentials : substr($credentials, 0, $colon);
            $userinfo = self::encode($user, self::USERINFO_UNSAFE);
            if ($colon !== false) {
                $userinfo .= ':' . self::encode(substr($credentials, $colon + 1), self::USERINFO_UNSAFE);
            }
            $userinfo .= '@';
        }

        $port = '';
        if (str_starts_with($authority, '[')) {
            $close = strpos($authority, ']');
            if ($close !== false) {
                $tail = substr($authority, $close + 1);
                $authority = substr($authority, 0, $close + 1);
                if ($tail !== '' && !str_starts_with($tail, ':')) {
                    return null;
                }
                $port = $tail === '' ? '' : substr($tail, 1);
            }
        } else {
            $colon = strrpos($authority, ':');
            if ($colon !== false) {
                $port = substr($authority, $colon + 1);
                $authority = substr($authority, 0, $colon);
            }
        }
        if ($port !== '' && (!ctype_digit($port) || (int) $port > self::MAX_PORT)) {
            return null;
        }
        if ($authority === '') {
            return null;
        }

        if (str_starts_with($authority, '[')) {
            $host = strtolower($authority);
            if (preg_match(self::IPV6_LITERAL_PATTERN, $host) !== 1) {
                return null;
            }
        } else {
            $host = self::normalizeHost($authority);
        }
        if ($host === '') {
            return null;
        }
        if ($port !== '' && (int) $port !== self::DEFAULT_PORTS[$scheme]) {
            $host .= ':' . (int) $port;
        }
        return $userinfo . $host;
    }

    /** Lowercases a domain host and converts it to punycode when {@code intl} can; {@code ''} when it is not a valid host. */
    private static function normalizeHost(string $host): string
    {
        $decoded = rawurldecode($host);
        $lower = mb_strtolower($decoded, 'UTF-8');
        if (preg_match(self::FORBIDDEN_HOST_PATTERN, $lower) === 1) {
            return '';
        }
        if (preg_match('/[^\x20-\x7E]/', $lower) === 1 && function_exists('idn_to_ascii')) {
            $info = [];
            // IDNA_CHECK_BIDI is off: Node's `new URL()` accepts hosts that ICU's RFC 5893 check rejects (such as
            // `١.com`), and ICU flags both cases with the same error, so a host that Node rejects is punycoded
            // instead, the smaller divergence.
            $flags = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_CONTEXTJ;
            $ascii = idn_to_ascii($lower, $flags, INTL_IDNA_VARIANT_UTS46, $info);
            if ($ascii === false && (($info['errors'] ?? -1) & ~self::IGNORED_IDNA_ERRORS) === 0) {
                $ascii = $info['result'] ?? false;
            }
            // The UTS #46 mapping turns fullwidth look-alikes into the forbidden ASCII characters.
            if (!is_string($ascii) || $ascii === '' || preg_match(self::FORBIDDEN_HOST_PATTERN, $ascii) === 1) {
                return '';
            }
            return $ascii;
        }
        return $lower;
    }

    /** Resolves dot segments of a path and percent-encodes it; an empty path becomes {@code /}. */
    private static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        $segments = explode('/', str_replace('\\', '/', $path));
        array_shift($segments);
        $resolved = [];
        $lastIndex = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            $lower = strtolower($segment);
            $isParent = in_array($lower, ['..', '.%2e', '%2e.', '%2e%2e'], true);
            $isCurrent = in_array($lower, ['.', '%2e'], true);
            if ($isParent) {
                array_pop($resolved);
            } elseif (!$isCurrent) {
                $resolved[] = self::encode($segment, self::PATH_UNSAFE);
            }
            if (($isParent || $isCurrent) && $index === $lastIndex) {
                $resolved[] = '';
            }
        }
        return '/' . implode('/', $resolved);
    }

    /**
     * Percent-encodes every byte that is a control, a space, outside printable ASCII, or in
     * {@code $unsafe}, leaving existing {@code %XX} escapes as they are.
     */
    private static function encode(string $value, string $unsafe): string
    {
        $encoded = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);
            $needsEncoding = $byte <= self::LAST_CONTROL_OR_SPACE || $byte > self::LAST_PRINTABLE || str_contains($unsafe, $value[$i]);
            $encoded .= $needsEncoding ? sprintf('%%%02X', $byte) : $value[$i];
        }
        return $encoded;
    }
}
