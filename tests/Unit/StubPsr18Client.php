<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A PSR-18 client that answers every request with the same response and counts the requests. */
final class StubPsr18Client implements ClientInterface
{
    public int $calls = 0;

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls++;
        return $this->response;
    }
}
