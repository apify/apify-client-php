<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

use RuntimeException;

/**
 * Base class of the errors for a failed API interaction: {@see ApifyApiException}, its status subclasses and
 * {@see InvalidResponseBodyException}. Catch it to handle them all.
 *
 * An invalid argument throws {@code \InvalidArgumentException} before any request, and a network failure or
 * timeout throws {@see TransportException}. Neither extends this class.
 */
class ApifyClientException extends RuntimeException
{
}
