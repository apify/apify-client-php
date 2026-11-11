<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

/** The API answered 429 Too Many Requests. Thrown once the retries are exhausted. */
class RateLimitException extends ApifyApiException
{
}
