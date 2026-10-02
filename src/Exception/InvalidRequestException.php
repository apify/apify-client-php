<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

/** Thrown when the Apify API responds with HTTP 400 Bad Request, typically a failed validation. */
class InvalidRequestException extends ApifyApiException
{
}
