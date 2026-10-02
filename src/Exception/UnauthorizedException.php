<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

/** Thrown when the Apify API responds with HTTP 401 Unauthorized: the token is missing or invalid. */
class UnauthorizedException extends ApifyApiException
{
}
