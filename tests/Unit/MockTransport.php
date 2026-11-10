<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\Exception\TransportException;
use Apify\Client\Http\HttpClientInterface;
use Apify\Client\Http\NoRedirectHttpClientInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A scripted {@see HttpClientInterface} for offline tests. Queued responses or {@see TransportException}s are
 * returned in order. Requests are recorded, with whether each was streaming and without redirects.
 */
final class MockTransport implements NoRedirectHttpClientInterface
{
    /** @var list<ResponseInterface|TransportException> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $received = [];

    /** @var list<bool> Whether each received request was sent with redirects disabled, in order. */
    public array $withoutRedirects = [];

    /** @var list<bool> Whether each received request was sent streaming, in order. */
    public array $streamed = [];

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

    /** Queues a response as it is, for example one with a non-seekable body. */
    public function queueRawResponse(ResponseInterface $response): self
    {
        $this->queue[] = $response;
        return $this;
    }

    public function lastRequest(): RequestInterface
    {
        if ($this->received === []) {
            throw new RuntimeException('no request was received');
        }
        return $this->received[count($this->received) - 1];
    }

    /** Reads the body of a recorded request, decompressing it when it carries a {@code Content-Encoding}. */
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
        return $this->record($request, $timeoutSecs, false, false);
    }

    private function record(RequestInterface $request, float $timeoutSecs, bool $stream, bool $withoutRedirects): ResponseInterface
    {
        $this->received[] = $request;
        $this->timeouts[] = $timeoutSecs;
        $this->streamed[] = $stream;
        $this->withoutRedirects[] = $withoutRedirects;
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
        return $this->record($request, $timeoutSecs, true, false);
    }

    public function sendWithoutRedirects(RequestInterface $request, float $timeoutSecs, bool $stream = false): ResponseInterface
    {
        return $this->record($request, $timeoutSecs, $stream, true);
    }
}
