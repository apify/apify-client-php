<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Exception\ApifyClientException;
use Apify\Client\Exception\InvalidResponseBodyException;
use Apify\Client\Exception\NotFoundException;
use Apify\Client\Exception\TransportException;
use Apify\Client\Http\GuzzleHttpClient;
use Apify\Client\Http\Psr18HttpClient;
use Apify\Client\Internal\Json;
use Apify\Client\Options\ActorServerRequestOptions;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Offline tests for {@see \Apify\Client\Resource\ActorServerClient}: the server URL lookup, the OpenAPI
 * specification lookup and the requests to the Actor's server, driven by a scripted transport.
 */
final class ActorServerClientTest extends TestCase
{
    private const SERVER_URL = 'https://jane--my-actor.apify.actor';

    private function client(MockTransport $transport, int $maxRetries = 2): ApifyClient
    {
        return new ApifyClient(token: 'secret-token', maxRetries: $maxRetries, minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    /** @param array<string,mixed> $extra */
    private static function actorBody(?string $standbyUrl = self::SERVER_URL, array $extra = []): string
    {
        return Json::encode(['data' => array_merge(['id' => 'a1', 'standbyUrl' => $standbyUrl], $extra)]);
    }

    /** @param array<string,mixed>|null $actorDefinition */
    private static function buildBody(string $id, string $buildNumber, ?array $actorDefinition = null): string
    {
        $build = ['id' => $id, 'buildNumber' => $buildNumber];
        if ($actorDefinition !== null) {
            $build['actorDefinition'] = $actorDefinition;
        }
        return Json::encode(['data' => $build]);
    }

    // ----- getUrl() ----------------------------------------------------------------

    public function testGetUrlFetchesTheActorOnceAndCachesTheUrl(): void
    {
        $transport = (new MockTransport())->queueResponse(200, self::actorBody());
        $server = $this->client($transport)->actor('jane/my-actor')->server();

        self::assertSame(self::SERVER_URL, $server->getUrl());
        self::assertSame(self::SERVER_URL, $server->getUrl());

        self::assertSame(1, $transport->callCount());
        self::assertSame('GET', $transport->lastRequest()->getMethod());
        self::assertStringEndsWith('/v2/actors/jane~my-actor', (string) $transport->lastRequest()->getUri());
    }

    public function testServerReturnsTheSameClientSoTheUrlIsSharedAcrossCalls(): void
    {
        $transport = (new MockTransport())->queueResponse(200, self::actorBody());
        $actor = $this->client($transport)->actor('a1');

        self::assertSame($actor->server(), $actor->server());
        $actor->server()->getUrl();
        $actor->server()->getUrl();
        self::assertSame(1, $transport->callCount());
    }

    public function testGetUrlRejectsAnActorThatIsNotAServerActor(): void
    {
        $transport = (new MockTransport())->queueResponse(200, self::actorBody(null));

        $this->expectException(ApifyClientException::class);
        $this->expectExceptionMessage('Actor a1 is not a Server Actor.');
        $this->client($transport)->actor('a1')->server()->getUrl();
    }

    public function testAFailedLookupIsNotCached(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::actorBody(null))
            ->queueResponse(200, self::actorBody());
        $server = $this->client($transport)->actor('a1')->server();

        try {
            $server->getUrl();
            self::fail('expected an ApifyClientException');
        } catch (ApifyClientException) {
        }

        self::assertSame(self::SERVER_URL, $server->getUrl());
    }

    public function testGetUrlThrowsNotFoundForAMissingActor(): void
    {
        $transport = (new MockTransport())->queueResponse(404, Json::encode(['error' => ['type' => 'record-not-found', 'message' => 'Actor was not found']]));

        $this->expectException(NotFoundException::class);
        $this->client($transport)->actor('missing')->server()->getUrl();
    }

    // ----- getOpenApiSpec() -----------------------------------------------------------

    public function testGetOpenApiSpecReadsTheWebServerSchemaOfTheLatestBuildByDefault(): void
    {
        $schema = ['openapi' => '3.0.0', 'paths' => ['/search' => []]];
        $transport = (new MockTransport())
            ->queueResponse(200, self::actorBody(extra: ['taggedBuilds' => ['latest' => ['buildId' => 'b-latest'], 'beta' => ['buildId' => 'b-beta']]]))
            ->queueResponse(200, self::buildBody('b-latest', '0.1.5', ['webServerSchema' => $schema]));

        $spec = $this->client($transport)->actor('a1')->server()->getOpenApiSpec();

        self::assertSame($schema, $spec);
        self::assertStringEndsWith('/v2/actor-builds/b-latest', (string) $transport->lastRequest()->getUri());
    }

    public function testGetOpenApiSpecUsesTheBuildTagTheActorStandbyNames(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::actorBody(extra: [
                'actorStandby' => ['build' => 'beta'],
                'taggedBuilds' => ['latest' => ['buildId' => 'b-latest'], 'beta' => ['buildId' => 'b-beta']],
            ]))
            ->queueResponse(200, self::buildBody('b-beta', '0.2.0', ['webServerSchema' => ['openapi' => 'beta']]));

        $spec = $this->client($transport)->actor('a1')->server()->getOpenApiSpec();

        self::assertSame(['openapi' => 'beta'], $spec);
        self::assertStringEndsWith('/v2/actor-builds/b-beta', (string) $transport->lastRequest()->getUri());
    }

    public function testGetOpenApiSpecLooksUpABuildNumberInTheBuildList(): void
    {
        $page = Json::encode(['data' => ['items' => [
            ['id' => 'b3', 'buildNumber' => '0.1.7'],
            ['id' => 'b2', 'buildNumber' => '0.1.6'],
            ['id' => 'b1', 'buildNumber' => '0.1.5'],
        ], 'total' => 3, 'offset' => 0, 'limit' => 1000, 'count' => 3, 'desc' => true]]);
        $transport = (new MockTransport())
            ->queueResponse(200, self::actorBody(extra: ['actorStandby' => ['build' => '0.1.6'], 'taggedBuilds' => []]))
            ->queueResponse(200, $page)
            ->queueResponse(200, self::buildBody('b2', '0.1.6', ['webServerSchema' => ['openapi' => 'by-number']]));

        $spec = $this->client($transport)->actor('a1')->server()->getOpenApiSpec();

        self::assertSame(['openapi' => 'by-number'], $spec);
        self::assertStringContainsString('/v2/actors/a1/builds?', (string) $transport->received[1]->getUri());
        self::assertStringContainsString('desc=1', (string) $transport->received[1]->getUri());
        self::assertStringEndsWith('/v2/actor-builds/b2', (string) $transport->lastRequest()->getUri());
    }

    public function testGetOpenApiSpecStopsLookingAtAnOlderBuildOfTheSameVersion(): void
    {
        $page = Json::encode(['data' => ['items' => [
            ['id' => 'b3', 'buildNumber' => '0.1.7'],
            ['id' => 'b1', 'buildNumber' => '0.1.5'],
        ], 'total' => 2, 'offset' => 0, 'limit' => 1000, 'count' => 2, 'desc' => true]]);
        // Only one page is queued: meeting 0.1.5 means 0.1.6 does not exist, so the next page is never read.
        $transport = (new MockTransport())
            ->queueResponse(200, self::actorBody(extra: ['actorStandby' => ['build' => '0.1.6']]))
            ->queueResponse(200, $page);

        try {
            $this->client($transport)->actor('a1')->server()->getOpenApiSpec();
            self::fail('expected an ApifyClientException');
        } catch (ApifyClientException $e) {
            self::assertSame('Build 0.1.6 of Actor a1 does not exist.', $e->getMessage());
        }
        self::assertSame(2, $transport->callCount());
    }

    public function testGetOpenApiSpecRejectsAnUnknownTag(): void
    {
        $transport = (new MockTransport())->queueResponse(200, self::actorBody(extra: ['actorStandby' => ['build' => 'nope'], 'taggedBuilds' => []]));

        $this->expectException(ApifyClientException::class);
        $this->expectExceptionMessage('Build nope of Actor a1 does not exist.');
        $this->client($transport)->actor('a1')->server()->getOpenApiSpec();
    }

    public function testGetOpenApiSpecReturnsNullWhenTheDefinitionHasNoWebServerSchema(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::actorBody(extra: ['taggedBuilds' => ['latest' => ['buildId' => 'b1']]]))
            ->queueResponse(200, self::buildBody('b1', '0.1.1', ['actorSpecification' => 1]));

        self::assertNull($this->client($transport)->actor('a1')->server()->getOpenApiSpec());
    }

    public function testGetOpenApiSpecAlsoCachesTheServerUrl(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::actorBody(extra: ['taggedBuilds' => ['latest' => ['buildId' => 'b1']]]))
            ->queueResponse(200, self::buildBody('b1', '0.1.1', ['webServerSchema' => []]));
        $server = $this->client($transport)->actor('a1')->server();

        $server->getOpenApiSpec();

        self::assertSame(self::SERVER_URL, $server->getUrl());
        self::assertSame(2, $transport->callCount());
    }

    // ----- request() -------------------------------------------------------------------

    private function serverTransport(): MockTransport
    {
        return (new MockTransport())->queueResponse(200, self::actorBody());
    }

    public function testRequestGoesToTheServerUrlWithTheTokenInTheServerAuthorizationHeader(): void
    {
        $transport = $this->serverTransport()->queueResponse(200, '{"hits":3}', ['Content-Type' => 'application/json']);

        $response = $this->client($transport)->actor('a1')->server()->request('/search', new ActorServerRequestOptions(params: ['q' => 'apify web', 'page' => 2, 'exact' => true, 'tags' => ['a', 'b'], 'skipped' => null]));

        $request = $transport->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::SERVER_URL . '/search?q=apify%20web&page=2&exact=1&tags=a%2Cb', (string) $request->getUri());
        self::assertSame('Bearer secret-token', $request->getHeaderLine('X-Apify-Authorization'));
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertStringStartsWith('ApifyClient/', $request->getHeaderLine('User-Agent'));
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->isSuccessful());
        self::assertSame(['hits' => 3], $response->getData());
        self::assertSame('{"hits":3}', $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
    }

    public function testRequestSendsNoRedirectsAndTheUserHeadersAsTheyAre(): void
    {
        $transport = $this->serverTransport()->queueResponse(200, 'ok');

        $this->client($transport)->actor('a1')->server()->request('/', new ActorServerRequestOptions(headers: ['Authorization' => 'Bearer actor-secret', 'X-Custom' => 'yes']));

        $request = $transport->lastRequest();
        self::assertSame('Bearer actor-secret', $request->getHeaderLine('Authorization'));
        self::assertSame('Bearer secret-token', $request->getHeaderLine('X-Apify-Authorization'));
        self::assertSame('yes', $request->getHeaderLine('X-Custom'));
        // The lookup of the Actor is an API call and follows redirects, the request to the server does not.
        self::assertSame([false, true], $transport->withoutRedirects);
    }

    public function testRequestUsesTheLongTierTimeoutByDefaultAndAnExplicitOne(): void
    {
        $transport = $this->serverTransport()->queueResponse(200, 'a')->queueResponse(200, 'b');
        $client = new ApifyClient(token: 't', timeoutSecs: 5, timeoutShortSecs: 3, timeoutLongSecs: 90, timeoutMaxSecs: 120, httpClient: $transport);
        $server = $client->actor('a1')->server();

        $server->request('/');
        $server->request('/', timeoutSecs: 7);

        self::assertSame([3.0, 90.0, 7.0], $transport->timeouts);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function pathProvider(): array
    {
        return [
            'empty path is the root' => ['', self::SERVER_URL . '/'],
            'slash is the root' => ['/', self::SERVER_URL . '/'],
            'relative path' => ['search/items', self::SERVER_URL . '/search/items'],
            'fragment is dropped' => ['/search#top', self::SERVER_URL . '/search'],
            'leading slashes cannot make a host' => ['//evil.example/steal', self::SERVER_URL . '/evil.example/steal'],
            'an absolute URL stays a path' => ['https://evil.example/steal', self::SERVER_URL . '/https://evil.example/steal'],
        ];
    }

    #[DataProvider('pathProvider')]
    public function testRequestAlwaysResolvesThePathAgainstTheServerUrl(string $path, string $expectedUrl): void
    {
        $transport = $this->serverTransport()->queueResponse(200, 'ok');

        $this->client($transport)->actor('a1')->server()->request($path);

        self::assertSame($expectedUrl, (string) $transport->lastRequest()->getUri());
    }

    public function testRequestIgnoresATrailingSlashOfTheServerUrl(): void
    {
        $transport = (new MockTransport())->queueResponse(200, self::actorBody(self::SERVER_URL . '/'))->queueResponse(200, 'ok');

        $this->client($transport)->actor('a1')->server()->request('/x');

        self::assertSame(self::SERVER_URL . '/x', (string) $transport->lastRequest()->getUri());
    }

    public function testRequestSerializesAnArrayBodyAsJsonAndAStringAsItIs(): void
    {
        $transport = $this->serverTransport()->queueResponse(200, 'a')->queueResponse(200, 'b')->queueResponse(200, 'c');
        $server = $this->client($transport)->actor('a1')->server();

        $server->request('/json', new ActorServerRequestOptions(method: 'post', body: ['query' => 'apify']));
        $json = $transport->lastRequest();
        $server->request('/text', new ActorServerRequestOptions(method: 'PUT', body: 'raw bytes', headers: ['content-type' => 'text/plain']));
        $text = $transport->lastRequest();
        $server->request('/plain', new ActorServerRequestOptions(method: 'POST', body: 'no content type'));
        $plain = $transport->lastRequest();

        self::assertSame('POST', $json->getMethod());
        self::assertSame('application/json', $json->getHeaderLine('Content-Type'));
        self::assertSame('{"query":"apify"}', (string) $json->getBody());
        self::assertSame('PUT', $text->getMethod());
        self::assertSame(['text/plain'], $text->getHeader('Content-Type'));
        self::assertSame('raw bytes', (string) $text->getBody());
        self::assertSame('', $plain->getHeaderLine('Content-Type'));
        self::assertSame('no content type', (string) $plain->getBody());
    }

    public function testRequestBodiesAreSentUncompressedUnlessCompressionIsSet(): void
    {
        $large = str_repeat('{"key":"value"},', 400);
        $transport = $this->serverTransport()->queueResponse(200, 'a')->queueResponse(200, 'b');
        $server = $this->client($transport)->actor('a1')->server();

        $server->request('/plain', new ActorServerRequestOptions(method: 'POST', body: $large, headers: ['Content-Type' => 'application/json']));
        $plain = $transport->lastRequest();
        $server->request('/gzip', new ActorServerRequestOptions(method: 'POST', body: $large, headers: ['Content-Type' => 'application/json'], compression: 'gzip'));
        $gzip = $transport->lastRequest();

        self::assertFalse($plain->hasHeader('Content-Encoding'));
        self::assertSame($large, (string) $plain->getBody());
        self::assertSame('gzip', $gzip->getHeaderLine('Content-Encoding'));
        self::assertSame($large, gzdecode((string) $gzip->getBody()));
    }

    public function testACallerContentTypeThatIsAlreadyCompressedKeepsTheBodyUncompressed(): void
    {
        $png = str_repeat("\x89PNG", 800);
        $transport = $this->serverTransport()->queueResponse(200, 'a');

        $this->client($transport)->actor('a1')->server()->request('/', new ActorServerRequestOptions(method: 'POST', body: $png, headers: ['content-type' => 'image/png'], compression: 'gzip'));

        self::assertFalse($transport->lastRequest()->hasHeader('Content-Encoding'));
        self::assertSame($png, (string) $transport->lastRequest()->getBody());
        self::assertSame('image/png', $transport->lastRequest()->getHeaderLine('Content-Type'));
    }

    public function testACompressedBodyDropsTheCallerContentLength(): void
    {
        $large = str_repeat('{"key":"value"},', 400);
        $transport = $this->serverTransport()->queueResponse(200, 'a');

        $this->client($transport)->actor('a1')->server()->request('/', new ActorServerRequestOptions(method: 'POST', body: $large, headers: ['content-length' => (string) strlen($large), 'Content-Type' => 'application/json'], compression: 'gzip'));

        $sent = $transport->lastRequest();
        self::assertSame('gzip', $sent->getHeaderLine('Content-Encoding'));
        self::assertFalse($sent->hasHeader('Content-Length'));
    }

    public function testTheClientWideCompressionDoesNotApplyToServerRequests(): void
    {
        $large = str_repeat('{"key":"value"},', 400);
        $transport = $this->serverTransport()->queueResponse(200, 'a');
        $client = new ApifyClient(token: 't', compression: 'gzip', httpClient: $transport);

        $client->actor('a1')->server()->request('/', new ActorServerRequestOptions(method: 'POST', body: $large, headers: ['Content-Type' => 'application/json']));

        self::assertFalse($transport->lastRequest()->hasHeader('Content-Encoding'));
    }

    public function testRequestRejectsAnUnknownCompressionAndABadQueryParameter(): void
    {
        $transport = $this->serverTransport()->queueResponse(200, 'a');
        $server = $this->client($transport)->actor('a1')->server();

        try {
            $server->request('/', new ActorServerRequestOptions(compression: 'zstd'));
            self::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('unknown compression "zstd"', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $server->request('/', new ActorServerRequestOptions(params: ['nested' => ['a' => 1]]));
    }

    public function testErrorStatusesAreReturnedNotThrown(): void
    {
        $transport = $this->serverTransport()
            ->queueResponse(404, '{"error":"no such item"}', ['Content-Type' => 'application/json'])
            ->queueResponse(500, 'boom', ['Content-Type' => 'text/plain']);
        $server = $this->client($transport)->actor('a1')->server();

        $notFound = $server->request('/missing');
        $failure = $server->request('/broken');

        self::assertSame(404, $notFound->getStatusCode());
        self::assertFalse($notFound->isSuccessful());
        self::assertSame(['error' => 'no such item'], $notFound->getData());
        self::assertSame(500, $failure->getStatusCode());
        self::assertSame('boom', $failure->getData());
        // A 500 comes from the Actor's code, which may have handled the request: no retry.
        self::assertSame(3, $transport->callCount());
    }

    public function testOnlyRateLimitAndServiceUnavailableAreRetriedAndTheLastOneIsReturned(): void
    {
        $transport = $this->serverTransport()
            ->queueResponse(429, 'slow down')
            ->queueResponse(503, 'starting')
            ->queueResponse(200, 'finally');
        $server = $this->client($transport)->actor('a1')->server();

        self::assertSame('finally', $server->request('/')->getBody());
        self::assertSame(4, $transport->callCount());

        $exhausted = $this->serverTransport()->queueResponse(503, 'one')->queueResponse(503, 'two')->queueResponse(503, 'three');
        $response = $this->client($exhausted, maxRetries: 2)->actor('a1')->server()->request('/');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('three', $response->getBody());
        self::assertSame(4, $exhausted->callCount());
    }

    public function testATransportErrorIsNotRetried(): void
    {
        $transport = $this->serverTransport()->queueError(timeout: true)->queueResponse(200, 'never sent');
        $server = $this->client($transport)->actor('a1')->server();

        try {
            $server->request('/slow');
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertTrue($e->isTimeout());
        }
        self::assertSame(2, $transport->callCount());
    }

    public function testARedirectIsReturnedAsItIs(): void
    {
        $transport = $this->serverTransport()->queueResponse(302, '', ['Location' => 'https://elsewhere.example/']);

        $response = $this->client($transport)->actor('a1')->server()->request('/old');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://elsewhere.example/', $response->getHeaderLine('Location'));
        self::assertSame(2, $transport->callCount());
    }

    public function testTheStreamOptionReadsTheBodyOnDemand(): void
    {
        $transport = $this->serverTransport()->queueResponse(200, 'line one');

        $response = $this->client($transport)->actor('a1')->server()->request('/events', new ActorServerRequestOptions(stream: true));

        self::assertSame([false, true], $transport->streamed);
        self::assertSame('line one', (string) $response->getBodyStream());
    }

    public function testTheRequestFailsBeforeSendingWhenTheTransportCannotDisableRedirects(): void
    {
        $actor = new Response(200, [], self::actorBody());
        $psr18 = new StubPsr18Client($actor);
        $client = new ApifyClient(token: 't', httpClient: new Psr18HttpClient($psr18));

        try {
            $client->actor('a1')->server()->request('/');
            self::fail('expected an ApifyClientException');
        } catch (ApifyClientException $e) {
            self::assertStringContainsString('cannot disable redirects', $e->getMessage());
        }
        // Only the lookup of the Actor went out.
        self::assertSame(1, $psr18->calls);
    }

    // ----- ActorServerResponse::getData() ----------------------------------------------

    public function testGetDataParsesByContentType(): void
    {
        $transport = $this->serverTransport()
            ->queueResponse(200, '{"a":[1,2]}', ['Content-Type' => 'application/vnd.api+json; charset=utf-8'])
            ->queueResponse(200, 'hello', ['Content-Type' => 'text/plain'])
            ->queueResponse(200, "\x00\x01", ['Content-Type' => 'application/octet-stream'])
            ->queueResponse(200, '')
            ->queueResponse(200, '{"a":1}' . "\n", ['Content-Type' => 'application/json-seq']);
        $server = $this->client($transport)->actor('a1')->server();

        self::assertSame(['a' => [1, 2]], $server->request('/1')->getData());
        self::assertSame('hello', $server->request('/2')->getData());
        self::assertSame("\x00\x01", $server->request('/3')->getData());
        self::assertSame('', $server->request('/4')->getData());
        self::assertSame('{"a":1}' . "\n", $server->request('/5')->getData());
    }

    public function testGetDataThrowsForAnInvalidJsonSuccessBodyAndReturnsRawBytesForAnErrorBody(): void
    {
        $transport = $this->serverTransport()
            ->queueResponse(200, '{broken', ['Content-Type' => 'application/json'])
            ->queueResponse(502, '<html>bad gateway</html>', ['Content-Type' => 'application/json']);
        $server = $this->client($transport)->actor('a1')->server();

        $failure = $server->request('/ok');
        try {
            $failure->getData();
            self::fail('expected an InvalidResponseBodyException');
        } catch (InvalidResponseBodyException $e) {
            self::assertSame('{broken', $e->getRawBody());
        }

        self::assertSame('<html>bad gateway</html>', $server->request('/bad')->getData());
    }

    // ----- GuzzleHttpClient ---------------------------------------------------------------

    public function testGuzzleTransportFollowsRedirectsUnlessAskedNotTo(): void
    {
        $redirect = new Response(302, ['Location' => 'https://elsewhere.example/']);
        $request = new Request('GET', 'https://example.com/start');

        $followingMock = new MockHandler([$redirect, new Response(200, [], 'landed')]);
        $following = new GuzzleHttpClient(new Guzzle(['handler' => HandlerStack::create($followingMock)]));
        self::assertSame('landed', (string) $following->send($request, 5.0)->getBody());

        $stoppingMock = new MockHandler([$redirect, new Response(200, [], 'landed')]);
        $stopping = new GuzzleHttpClient(new Guzzle(['handler' => HandlerStack::create($stoppingMock)]));
        $response = $stopping->sendWithoutRedirects($request, 5.0);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, $stoppingMock->count(), 'the redirect target must not be requested');
    }
}
