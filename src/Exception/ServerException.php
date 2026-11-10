<?php

declare(strict_types=1);

namespace Apify\Client\Exception;

/** The API answered with a 5xx status. Thrown once the retries are exhausted. */
class ServerException extends ApifyApiException
{
}
