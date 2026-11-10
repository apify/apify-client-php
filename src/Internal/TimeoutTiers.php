<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use InvalidArgumentException;

/**
 * The named request timeout tiers: {@code 'short'} for metadata reads and writes, {@code 'medium'} for
 * listing, batch and trigger calls, and {@code 'long'} for downloads, uploads and streaming. A call can also
 * pass {@code 'noTimeout'}.
 *
 * Defaults: {@code short} 5 s, {@code medium} 30 s, and {@code long} and the cap {@code max} the client's
 * {@code $timeoutSecs} (360 s). A duration above the cap is capped.
 *
 * @internal
 */
final class TimeoutTiers
{
    public const TIER_SHORT = 'short';
    public const TIER_MEDIUM = 'medium';
    public const TIER_LONG = 'long';
    public const NO_TIMEOUT = 'noTimeout';

    /** The timeout meaning "none", which Guzzle and cURL read from {@code 0.0}. A {@code null} fallback never triggers on it. */
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
     * Builds the tiers. A {@code null} argument falls back to {@see DEFAULT_SHORT_SECS},
     * {@see DEFAULT_MEDIUM_SECS} or, for the {@code long} tier and the cap, {@code $defaultSecs}.
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
     * Resolves the timeout of a call: {@code null} is the duration of {@code $defaultTier}, a tier name that
     * tier's duration, {@see NO_TIMEOUT} {@see INFINITE_SECS}, and a number of seconds itself, capped at
     * {@see $maxSecs}.
     *
     * @param int|float|string|null $timeoutSecs seconds, a tier name, {@see NO_TIMEOUT} or {@code null}
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
