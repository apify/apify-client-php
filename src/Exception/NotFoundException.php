<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

/** Thrown when the Apify API responds with HTTP 404 Not Found. */
class NotFoundException extends ApifyApiException
{
}
