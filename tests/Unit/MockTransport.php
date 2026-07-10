<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\Exception\TransportException;
use Apify\Client\Http\HttpClientInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A scripted {@see HttpClientInterface} for offline unit tests. Each queued entry is either a
 * {@see ResponseInterface} to return or a {@see TransportException} to throw, consumed in order.
 * All received requests are recorded for assertions.
 */
final class MockTransport implements HttpClientInterface
{
    /** @var list<ResponseInterface|TransportException> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $received = [];

    /** @var list<float> The per-request timeout (seconds) each call was made with, in order. */
    public array $timeouts = [];

    /**
     * @param array<string,string> $headers
     */
    public function queueResponse(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers, $body);
        return $this;
    }

    public function queueError(bool $timeout = false): self
    {
        $this->queue[] = new TransportException('mock transport failure', null, $timeout);
        return $this;
    }

    public function lastRequest(): RequestInterface
    {
        if ($this->received === []) {
            throw new RuntimeException('no request was received');
        }
        return $this->received[count($this->received) - 1];
    }

    /**
     * Reads a recorded request's body as a string, transparently decompressing it when the client
     * applied request compression (a {@code Content-Encoding} header). Tests that assert on the body
     * shape use this so they stay agnostic to whether the payload went out compressed.
     */
    public static function readBody(RequestInterface $request): string
    {
        $raw = (string) $request->getBody();
        $encoding = $request->getHeaderLine('Content-Encoding');
        if ($encoding === 'gzip') {
            $decoded = gzdecode($raw);
        } elseif ($encoding === 'br') {
            // brotli_uncompress only exists when the PECL brotli extension is loaded; call it
            // indirectly so the symbol is not referenced statically when the extension is absent.
            $brotliUncompress = 'brotli_uncompress';
            $decoded = $brotliUncompress($raw);
        } else {
            return $raw;
        }
        if (!is_string($decoded)) {
            throw new RuntimeException('failed to decompress request body (encoding: ' . $encoding . ')');
        }
        return $decoded;
    }

    public function callCount(): int
    {
        return count($this->received);
    }

    public function send(RequestInterface $request, float $timeoutSecs): ResponseInterface
    {
        $this->received[] = $request;
        $this->timeouts[] = $timeoutSecs;
        if ($this->queue === []) {
            throw new RuntimeException('MockTransport queue is empty');
        }
        $next = array_shift($this->queue);
        if ($next instanceof TransportException) {
            throw $next;
        }
        return $next;
    }

    public function sendStreaming(RequestInterface $request, float $timeoutSecs): ResponseInterface
    {
        return $this->send($request, $timeoutSecs);
    }
}
