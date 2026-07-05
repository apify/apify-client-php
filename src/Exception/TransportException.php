<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

use RuntimeException;
use Throwable;

/**
 * Marks a transport-level (network/timeout) failure, which is retryable by the client.
 *
 * @internal
 */
final class TransportException extends RuntimeException
{
    private bool $timeout;

    public function __construct(string $message, ?Throwable $previous = null, bool $timeout = false)
    {
        parent::__construct($message, 0, $previous);
        $this->timeout = $timeout;
    }

    /** Whether the failure was caused by a request timeout. */
    public function isTimeout(): bool
    {
        return $this->timeout;
    }
}
