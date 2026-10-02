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
 * Unlike the reference client — which lowers the default duration of the {@code short}/{@code medium}
 * tiers (5s/30s) relative to v2's single 360s budget, a deliberate breaking change documented in its
 * v3 upgrade guide — every tier here defaults to this client's existing single {@code $timeoutSecs}
 * budget. A caller who never touches the new {@code timeout*Secs} constructor options therefore gets
 * byte-for-byte the same effective timeout on every call as before this feature existed; tightening a
 * tier is strictly opt-in, consistent with {@code client_requirements.md}'s rule against breaking the
 * pre-existing public interface for anything short of a bugfix.
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

    private function __construct(
        public readonly float $shortSecs,
        public readonly float $mediumSecs,
        public readonly float $longSecs,
        public readonly float $maxSecs,
    ) {
    }

    /**
     * Builds the tier configuration. Every tier argument left {@code null} falls back to
     * {@code $defaultSecs} (this client's single overall {@code $timeoutSecs}), so a client configured
     * without any of the new options behaves exactly as it did before tiers existed.
     */
    public static function create(
        float $defaultSecs,
        ?float $shortSecs,
        ?float $mediumSecs,
        ?float $longSecs,
        ?float $maxSecs,
    ): self {
        return new self(
            $shortSecs ?? $defaultSecs,
            $mediumSecs ?? $defaultSecs,
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
