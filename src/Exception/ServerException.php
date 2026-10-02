<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

/**
 * Thrown when the Apify API responds with an HTTP 5xx status. The client retries such requests with
 * exponential backoff, so this surfaces only once the retries are exhausted.
 */
class ServerException extends ApifyApiException
{
}
