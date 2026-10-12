<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

use Apify\Client\Exception\ApifyApiException;
use InvalidArgumentException;

/**
 * Backs the {@code waitForResources} option of {@see \Apify\Client\Resource\ActorClient::start()}/
 * {@code call()} and {@see \Apify\Client\Resource\TaskClient::start()}/{@code call()}: retries a run
 * start that the API rejects for lack of account resources, mirroring the reference client's
 * {@code startWaitingForResources}.
 *
 * @internal
 */
final class WaitForResources
{
    /**
     * Error types the API rejects a run start with while the account has no free memory or
     * concurrent-run slot for it. Both clear as other runs or builds finish.
     */
    private const RESOURCE_LIMIT_ERROR_TYPES = ['actor-memory-limit-exceeded', 'concurrent-runs-limit-exceeded'];

    /** Cooldown between two attempts to start a run that was rejected for lack of resources. */
    private const COOLDOWN_SECS = 10.0;

    private function __construct()
    {
    }

    /**
     * Calls {@code $start}, retrying it every {@see COOLDOWN_SECS} while it fails with an {@see
     * ApifyApiException} of one of {@see RESOURCE_LIMIT_ERROR_TYPES}. {@code true} retries until the
     * call succeeds; a number of seconds bounds the retrying, after which the last error is thrown;
     * {@code null}/{@code false} makes exactly one attempt. Any other error is thrown right away.
     *
     * @template T
     * @param callable():T $start
     * @throws InvalidArgumentException if {@code $waitForResources} is a negative number
     * @return T
     */
    public static function start(callable $start, bool|int|null $waitForResources): mixed
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
                $delaySecs = self::COOLDOWN_SECS;
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
