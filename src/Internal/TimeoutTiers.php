<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use InvalidArgumentException;

/**
 * The named request-timeout tiers, mirroring the reference client's {@code TimeoutTier}: {@code
 * 'short'} for metadata reads/writes, {@code 'medium'} for listing/batch/trigger calls, and {@code
 * 'long'} for downloads/uploads/streaming. {@code 'noTimeout'} (accepted per call, not a configured
 * duration) opts a single call out of any request timeout.
 *
 * Defaults match the reference client: {@code short} 5s, {@code medium} 30s, {@code long} and the
 * per-attempt cap {@code max} the client's overall {@code $timeoutSecs} (360s by default). Every
 * tier is configurable on the {@see \Apify\Client\ApifyClient} constructor; a tier or per-call
 * duration above the cap is capped at it.
 *
 * @internal
 */
final class TimeoutTiers
{
    public const TIER_SHORT = 'short';
    public const TIER_MEDIUM = 'medium';
    public const TIER_LONG = 'long';
    public const NO_TIMEOUT = 'noTimeout';

    /**
     * The request-timeout value meaning "no timeout", as sent to the transport. Both Guzzle
     * ({@code RequestOptions::TIMEOUT}) and the underlying cURL option it sets treat {@code 0.0} as
     * "wait indefinitely", so this sentinel reaches {@see \Apify\Client\Http\HttpClientInterface}
     * as an ordinary {@code float} with no special-cased {@code null} along the way — {@code 0.0} is
     * never confused with "no override" because every {@code ?? } fallback in this client only
     * triggers on {@code null}, not on a falsy {@code 0.0}.
     */
    public const INFINITE_SECS = 0.0;

    /** Default duration of the {@code short} tier, in seconds (metadata reads and writes). */
    public const DEFAULT_SHORT_SECS = 5.0;

    /** Default duration of the {@code medium} tier, in seconds (listing, batch and trigger calls). */
    public const DEFAULT_MEDIUM_SECS = 30.0;

    private function __construct(
        public readonly float $shortSecs,
        public readonly float $mediumSecs,
        public readonly float $longSecs,
        public readonly float $maxSecs,
    ) {
    }

    /**
     * Builds the tier configuration. A tier argument left {@code null} falls back to its default:
     * {@see DEFAULT_SHORT_SECS}, {@see DEFAULT_MEDIUM_SECS}, and, for the {@code long} tier and the
     * per-attempt cap, {@code $defaultSecs} (this client's overall {@code $timeoutSecs}).
     */
    public static function create(
        float $defaultSecs,
        ?float $shortSecs,
        ?float $mediumSecs,
        ?float $longSecs,
        ?float $maxSecs,
    ): self {
        return new self(
            $shortSecs ?? self::DEFAULT_SHORT_SECS,
            $mediumSecs ?? self::DEFAULT_MEDIUM_SECS,
            $longSecs ?? $defaultSecs,
            $maxSecs ?? $defaultSecs,
        );
    }

    /** The configured duration (seconds) of the given tier, capped at {@see $maxSecs}. */
    public function secondsFor(string $tier): float
    {
        $raw = match ($tier) {
            self::TIER_SHORT => $this->shortSecs,
            self::TIER_MEDIUM => $this->mediumSecs,
            self::TIER_LONG => $this->longSecs,
            default => throw new InvalidArgumentException('unknown timeout tier: ' . $tier),
        };
        return min($raw, $this->maxSecs);
    }

    /**
     * Resolves a per-call timeout override against this tier configuration: {@code null} uses
     * {@code $defaultTier}'s configured duration; a tier name ({@see TIER_SHORT}, {@see TIER_MEDIUM},
     * {@see TIER_LONG}) uses that tier's duration instead; {@see NO_TIMEOUT} returns {@see
     * INFINITE_SECS} (no request timeout at all — for calls that poll until a job finishes); a number
     * of seconds is used as-is, capped at {@see $maxSecs}.
     *
     * @param int|float|string|null $timeoutSecs a number of seconds, a tier name, {@see NO_TIMEOUT}, or
     *                                            {@code null} to use {@code $defaultTier}
     */
    public function resolve(int|float|string|null $timeoutSecs, string $defaultTier): float
    {
        if ($timeoutSecs === null) {
            return $this->secondsFor($defaultTier);
        }
        if ($timeoutSecs === self::NO_TIMEOUT) {
            return self::INFINITE_SECS;
        }
        if (is_string($timeoutSecs)) {
            return $this->secondsFor($timeoutSecs);
        }
        return min((float) $timeoutSecs, $this->maxSecs);
    }
}
