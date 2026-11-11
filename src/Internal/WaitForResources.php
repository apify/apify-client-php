<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Exception\ApifyApiException;
use InvalidArgumentException;

/**
 * Retries a run start that the API rejects for lack of account resources. Backs the {@code waitForResources}
 * option of the Actor and task {@code start()} and {@code call()}.
 *
 * @internal
 */
final class WaitForResources
{
    /** The errors for a start that finds no free memory or concurrent run slot. Both clear as other runs finish. */
    private const RESOURCE_LIMIT_ERROR_TYPES = ['actor-memory-limit-exceeded', 'concurrent-runs-limit-exceeded'];

    /** Seconds between two attempts. {@see start()} takes its own value so tests need not wait. */
    public const COOLDOWN_SECS = 10.0;

    private function __construct()
    {
    }

    /**
     * Calls {@code $start}, retrying every {@code $cooldownSecs} while it fails with one of
     * {@see RESOURCE_LIMIT_ERROR_TYPES}. {@code true} retries until it succeeds, a number of seconds for that
     * long, then the last error is thrown. {@code null} and {@code false} make one attempt. Any other error is
     * thrown at once.
     *
     * @template T
     * @param callable():T $start
     * @throws InvalidArgumentException if {@code $waitForResources} is negative
     * @throws ApifyApiException the last rejection once a bounded retry is exhausted, or any other error
     * @return T
     */
    public static function start(callable $start, bool|int|null $waitForResources, float $cooldownSecs = self::COOLDOWN_SECS): mixed
    {
        if ($waitForResources === null || $waitForResources === false) {
            return $start();
        }
        if (is_int($waitForResources) && $waitForResources < 0) {
            throw new InvalidArgumentException('waitForResources must not be negative');
        }
        $deadline = $waitForResources === true ? null : (microtime(true) + $waitForResources);

        while (true) {
            try {
                return $start();
            } catch (ApifyApiException $e) {
                if (!in_array($e->getType(), self::RESOURCE_LIMIT_ERROR_TYPES, true)) {
                    throw $e;
                }
                $delaySecs = $cooldownSecs;
                if ($deadline !== null) {
                    $remainingSecs = $deadline - microtime(true);
                    if ($remainingSecs <= 0) {
                        throw $e;
                    }
                    $delaySecs = min($delaySecs, $remainingSecs);
                }
                usleep((int) round($delaySecs * 1_000_000));
            }
        }
    }
}
