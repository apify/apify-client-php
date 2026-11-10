<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use PHPUnit\Framework\TestCase;

/**
 * Offline tests of {@see \Apify\Client\Resource\TaskClient::publish()} and
 * {@see \Apify\Client\Resource\TaskClient::unpublish()}. Both wrap {@see \Apify\Client\Resource\TaskClient::update()}, so the tests check the request they send.
 */
final class TaskPublishUnpublishTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', httpClient: $transport);
    }

    public function testPublishSendsIsPublicTrue(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => [
            'id' => 'task1',
            'isPublic' => true,
        ]]));

        $task = $this->client($transport)->task('task1')->publish();

        self::assertTrue($task->isPublic());
        $request = $transport->lastRequest();
        self::assertSame('PUT', $request->getMethod());
        self::assertStringContainsString('/actor-tasks/task1', (string) $request->getUri());
        self::assertSame(['isPublic' => true], Json::decode(MockTransport::readBody($request)));
    }

    public function testUnpublishSendsIsPublicFalse(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => [
            'id' => 'task1',
            'isPublic' => false,
        ]]));

        $task = $this->client($transport)->task('task1')->unpublish();

        self::assertNotTrue($task->isPublic());
        $request = $transport->lastRequest();
        self::assertSame('PUT', $request->getMethod());
        self::assertStringContainsString('/actor-tasks/task1', (string) $request->getUri());
        self::assertSame(['isPublic' => false], Json::decode(MockTransport::readBody($request)));
    }
}
