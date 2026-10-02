<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

/** Thrown when the Apify API responds with HTTP 403 Forbidden: the token lacks the required permission. */
class ForbiddenException extends ApifyApiException
{
}
