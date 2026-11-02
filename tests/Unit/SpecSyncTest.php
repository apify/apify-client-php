<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use Apify\Client\Options\RunListOptions;
use Apify\Client\Options\StoreListOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Offline tests for the endpoint parameters the OpenAPI specification added to endpoints this client
 * implements, for the by-ID user endpoints, for the schedule log, and for responses that carry a
 * documented {@code null} where a value used to be.
 */
final class SpecSyncTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    /** @return array<string,string> */
    private static function queryOf(MockTransport $transport): array
    {
        parse_str($transport->lastRequest()->getUri()->getQuery(), $query);
        return $query;
    }

    public function testRunListsSendTheDeploymentFilterAsACommaSeparatedList(): void
    {
        $page = Json::encode(['data' => ['items' => [], 'total' => 0, 'offset' => 0, 'limit' => 10, 'count' => 0, 'desc' => false]]);
        $options = new RunListOptions(status: ['RUNNING'], deployment: ['SINGLE_TENANT', 'MULTI_TENANT_FREE_USERS']);

        $transport = (new MockTransport())->queueResponse(200, $page);
        $this->client($transport)->runs()->list(null, $options);
        self::assertStringContainsString('/v2/actor-runs?', (string) $transport->lastRequest()->getUri());
        self::assertSame('SINGLE_TENANT,MULTI_TENANT_FREE_USERS', self::queryOf($transport)['deployment']);

        $transport = (new MockTransport())->queueResponse(200, $page);
        $this->client($transport)->actor('a1')->runs()->list(null, $options);
        self::assertStringContainsString('/v2/actors/a1/runs?', (string) $transport->lastRequest()->getUri());
        self::assertSame('SINGLE_TENANT,MULTI_TENANT_FREE_USERS', self::queryOf($transport)['deployment']);
    }

    public function testDeploymentFilterIsOmittedByDefault(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['items' => []]]));
        $this->client($transport)->runs()->list();

        self::assertArrayNotHasKey('deployment', self::queryOf($transport));
    }

    public function testStoreListSendsIncludeInputSchema(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['items' => []]]));
        $this->client($transport)->store()->list(new StoreListOptions(includeInputSchema: true));

        self::assertSame('1', self::queryOf($transport)['includeInputSchema']);
    }

    public function testStoreIterationKeepsIncludeInputSchemaOnEveryPage(): void
    {
        $options = (new StoreListOptions(includeInputSchema: true))->withPagination(10, 5);

        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['items' => []]]));
        $this->client($transport)->store()->list($options);

        self::assertSame('1', self::queryOf($transport)['includeInputSchema']);
        self::assertSame('10', self::queryOf($transport)['offset']);
    }

    public function testUserLimitsAndMonthlyUsageAreAvailableForAnyUser(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, Json::encode(['data' => ['limits' => ['maxConcurrentActorJobs' => 5]]]))
            ->queueResponse(200, Json::encode(['data' => ['usageCycle' => []]]))
            ->queueResponse(200, '');
        $client = $this->client($transport);

        self::assertSame(['limits' => ['maxConcurrentActorJobs' => 5]], $client->user('someone')->limits());
        $client->user('someone')->monthlyUsage('2026-10-01');
        $client->user('someone')->updateLimits(['maxMonthlyUsageUsd' => 10]);

        $uris = array_map(static fn ($request): string => (string) $request->getUri(), $transport->received);
        self::assertStringEndsWith('/v2/users/someone/limits', $uris[0]);
        self::assertStringEndsWith('/v2/users/someone/usage/monthly?date=2026-10-01', $uris[1]);
        self::assertStringEndsWith('/v2/users/someone/limits', $uris[2]);
        self::assertSame('PUT', $transport->lastRequest()->getMethod());
    }

    public function testScheduleLogReturnsTheInvocationEntries(): void
    {
        $entries = [
            ['message' => 'Schedule invoked', 'level' => 'INFO', 'createdAt' => '2026-10-01T10:00:00.000Z'],
            ['message' => 'Cannot start Actor task', 'level' => 'ERROR', 'createdAt' => '2026-10-01T11:00:00.000Z'],
        ];
        $transport = (new MockTransport())
            ->queueResponse(200, Json::encode(['data' => $entries]))
            ->queueResponse(200, Json::encode(['data' => []]));
        $client = new ApifyClient(token: 't', httpClient: $transport);

        self::assertSame($entries, $client->schedule('s1')->getLog());
        self::assertSame([], $client->schedule('s1')->getLog());
        self::assertStringEndsWith('/v2/schedules/s1/log', (string) $transport->lastRequest()->getUri());
        // The schedule log is read with the medium tier (30s by default), not the long download tier.
        self::assertSame([30.0, 30.0], $transport->timeouts);
    }

    /** @return iterable<string,array{string}> */
    public static function emptyScheduleLogBodies(): iterable
    {
        yield 'null data' => ['{"data":null}'];
        yield 'missing data' => ['{}'];
    }

    #[DataProvider('emptyScheduleLogBodies')]
    public function testScheduleLogWithoutEntriesIsAnEmptyList(string $body): void
    {
        $transport = (new MockTransport())->queueResponse(200, $body);

        self::assertSame([], $this->client($transport)->schedule('s1')->getLog());
    }

    public function testScheduleWithANullUiStateIsReadAsIs(): void
    {
        // The API returns `uiState: null` once an API update changed the cron expression or timezone.
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => [
            'id' => 's1',
            'cronExpression' => '0 12 * * *',
            'uiState' => null,
        ]]));

        $schedule = $this->client($transport)->schedule('s1')->get();

        self::assertNotNull($schedule);
        self::assertSame('0 12 * * *', $schedule->getCronExpression());
        self::assertNull($schedule->get('uiState'));
        self::assertArrayHasKey('uiState', $schedule->toArray());
    }

    public function testPendingStoredRequestWithANullHandledAtIsReadAsIs(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => [
            'id' => 'r1',
            'uniqueKey' => 'https://example.com',
            'url' => 'https://example.com',
            'handledAt' => null,
        ]]));

        $request = $this->client($transport)->requestQueue('q1')->getRequest('r1');

        self::assertNotNull($request);
        self::assertSame('r1', $request->getId());
        self::assertNull($request->get('handledAt'));
    }
}
