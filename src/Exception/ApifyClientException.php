<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

use RuntimeException;

/**
 * Base class of every error the client throws for a failed API interaction: {@see ApifyApiException}
 * and its HTTP-status subclasses, and {@see InvalidResponseBodyException}. A single {@code instanceof
 * ApifyClientException} check catches all of them, mirroring the reference JS client's
 * {@code ApifyClientError}.
 *
 * An invalid argument throws a built-in {@code \InvalidArgumentException} before any request is sent,
 * and a network/timeout failure surfaces as {@see TransportException}; neither extends this class,
 * matching the reference client, where argument validation errors and transport errors also stay
 * outside {@code ApifyClientError}.
 */
class ApifyClientException extends RuntimeException
{
}
