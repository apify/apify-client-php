<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Exception\ApifyClientException;
use Apify\Client\Exception\NotFoundException;
use Apify\Client\Options\ActorServerRequestOptions;

/** Runs {@see \Apify\Client\Resource\ActorServerClient} against a temporary Server Actor that echoes every request. */
final class ActorServerIntegrationTest extends IntegrationTestCase
{
    private const BUILD_WAIT_SECS = 300;

    /** The web server schema the echo Actor declares in its definition. */
    private const OPENAPI_SPEC = [
        'openapi' => '3.0.0',
        'info' => ['title' => 'Echo server', 'version' => '1.0.0'],
        'paths' => ['/echo' => ['post' => ['responses' => ['200' => ['description' => 'The echoed request.']]]]],
    ];

    /**
     * A web server that answers every request, including the platform's readiness probe, with the request it
     * received, under the status in the {@code status} query parameter, or 200.
     */
    private const ECHO_SERVER_SOURCE = <<<'JS'
const http = require('http');

http.createServer((req, res) => {
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
        const status = Number(new URL(req.url, 'http://localhost').searchParams.get('status')) || 200;
        res.writeHead(status, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ method: req.method, url: req.url, headers: req.headers, body }));
    });
}).listen(process.env.ACTOR_WEB_SERVER_PORT);
JS;

    private const DOCKERFILE = "FROM node:22-alpine\nCOPY main.js ./\nCMD [\"node\", \"main.js\"]\n";

    public function testServerClientReturnsTheUrlAndSpecificationAndSendsRequestsToTheServer(): void
    {
        $client = $this->requireClient();
        $actorName = self::uniqueName('server');
        $created = $client->actors()->create([
            'name' => $actorName,
            'versions' => [[
                'versionNumber' => '0.0',
                'sourceType' => 'SOURCE_FILES',
                'buildTag' => 'latest',
                'sourceFiles' => [
                    ['name' => 'Dockerfile', 'format' => 'TEXT', 'content' => self::DOCKERFILE],
                    ['name' => 'main.js', 'format' => 'TEXT', 'content' => self::ECHO_SERVER_SOURCE],
                    ['name' => '.actor/actor.json', 'format' => 'TEXT', 'content' => json_encode([
                        'actorSpecification' => 1,
                        'name' => $actorName,
                        'version' => '0.0',
                        'usesStandbyMode' => true,
                        'webServerSchema' => self::OPENAPI_SPEC,
                    ], JSON_THROW_ON_ERROR)],
                ],
            ]],
            'actorStandby' => ['isEnabled' => true, 'memoryMbytes' => 256, 'idleTimeoutSecs' => 60],
        ]);
        $actorId = (string) $created->getId();
        try {
            $build = $client->actor($actorId)->build('0.0');
            $finished = $client->build((string) $build->getId())->waitForFinish(self::BUILD_WAIT_SECS);
            self::assertSame('SUCCEEDED', $finished->getStatus());

            $actor = $client->actor($actorId)->get();
            self::assertNotNull($actor);
            $server = $client->actor($actorId)->server();

            self::assertSame($actor->getStandbyUrl(), $server->getUrl());
            self::assertSame(self::OPENAPI_SPEC, $server->getOpenApiSpec());

            $response = $server->request('/echo', new ActorServerRequestOptions(method: 'POST', params: ['q' => 'apify'], body: ['limit' => 5]));
            $echoed = $response->getData();
            self::assertIsArray($echoed);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('POST', $echoed['method']);
            self::assertSame('/echo?q=apify', $echoed['url']);
            self::assertSame(['limit' => 5], json_decode($echoed['body'], true));
            // The platform consumes the client's token, so it never reaches the Actor's code.
            self::assertArrayNotHasKey('x-apify-authorization', $echoed['headers']);
            self::assertArrayNotHasKey('authorization', $echoed['headers']);

            $own = $server->request('/echo', new ActorServerRequestOptions(headers: ['Authorization' => 'Bearer actor-token']))->getData();
            self::assertIsArray($own);
            self::assertSame('Bearer actor-token', $own['headers']['authorization']);

            $error = $server->request('/echo', new ActorServerRequestOptions(params: ['status' => 404]));
            self::assertSame(404, $error->getStatusCode());
            $errorBody = $error->getData();
            self::assertIsArray($errorBody);
            self::assertSame('/echo?status=404', $errorBody['url']);
        } finally {
            $client->actor($actorId)->delete();
        }
    }

    public function testServerClientRejectsAnActorThatIsNotAServerActor(): void
    {
        $client = $this->requireClient();
        $created = $client->actors()->create(self::minimalActor(self::uniqueName('not-server')));
        try {
            $this->expectException(ApifyClientException::class);
            $this->expectExceptionMessageMatches('/is not a Server Actor/');
            $client->actor((string) $created->getId())->server()->getUrl();
        } finally {
            $client->actor((string) $created->getId())->delete();
        }
    }

    public function testServerClientThrowsNotFoundForAMissingActor(): void
    {
        $client = $this->requireClient();

        $this->expectException(NotFoundException::class);
        $client->actor(self::uniqueName('missing'))->server()->getUrl();
    }
}
