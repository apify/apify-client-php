<?php

declare(strict_types=1);

namespace Apify\Client\Internal;

/**
 * Run/build status helpers.
 *
 * @internal
 */
final class Statuses
{
    /** Terminal run/build statuses: a resource in any of these is finished and will not change. */
    private const TERMINAL = ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT'];

    private function __construct()
    {
    }

    /** Reports whether the status is a terminal (finished) run/build status. */
    public static function isTerminal(?string $status): bool
    {
        return $status !== null && in_array($status, self::TERMINAL, true);
    }
}
