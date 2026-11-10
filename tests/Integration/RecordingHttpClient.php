<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Http\GuzzleHttpClient;
use Apify\Client\Http\HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Sends requests with the default transport and records the {@code Content-Encoding} of each non-streaming one. */
final class RecordingHttpClient implements HttpClientInterface
{
    /** @var list<string> */
    public array $encodings = [];

    private HttpClientInterface $inner;

    public function __construct()
    {
        $this->inner = new GuzzleHttpClient();
    }

    public function send(RequestInterface $request, float $timeoutSecs): ResponseInterface
    {
        $this->encodings[] = $request->getHeaderLine('Content-Encoding');
        return $this->inner->send($request, $timeoutSecs);
    }

    public function sendStreaming(RequestInterface $request, float $timeoutSecs): ResponseInterface
    {
        return $this->inner->sendStreaming($request, $timeoutSecs);
    }
}
