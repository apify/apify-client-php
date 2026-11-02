<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use Apify\Client\Model\ActorEnvVar;
use Apify\Client\Model\RequestQueueRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the default timeout tier of the methods whose tier differs from the one a reader might expect,
 * to the tier the reference client assigns them. The default tiers last 5s (short), 30s (medium) and
 * 360s (long).
 */
final class MethodTierTest extends TestCase
{
    private const SHORT = 5.0;
    private const MEDIUM = 30.0;
    private const LONG = 360.0;

    private const ITEM_BODY = '{"data":{"id":"x","name":"n","secretKey":"k"}}';
    private const PAGE_BODY = '{"data":{"items":[],"total":0,"offset":0,"limit":10,"count":0,"desc":false}}';

    /** @return iterable<string,array{callable(ApifyClient):mixed,float,string}> */
    public static function tierCases(): iterable
    {
        yield 'Actor validateInput' => [static fn (ApifyClient $c) => $c->actor('a')->validateInput(), self::SHORT, '{"valid":true}'];
        yield 'Build abort' => [static fn (ApifyClient $c) => $c->build('b')->abort(), self::SHORT, self::ITEM_BODY];
        yield 'Actor env var list' => [static fn (ApifyClient $c) => $c->actor('a')->version('0.1')->envVars()->list(), self::SHORT, self::PAGE_BODY];
        yield 'Actor env var create' => [static fn (ApifyClient $c) => $c->actor('a')->version('0.1')->envVars()->create(new ActorEnvVar('N', 'v')), self::SHORT, self::ITEM_BODY];
        yield 'Actor version list' => [static fn (ApifyClient $c) => $c->actor('a')->versions()->list(), self::SHORT, self::PAGE_BODY];
        yield 'Actor version create' => [static fn (ApifyClient $c) => $c->actor('a')->versions()->create(['versionNumber' => '0.1']), self::SHORT, self::ITEM_BODY];
        yield 'Dataset getOrCreate' => [static fn (ApifyClient $c) => $c->datasets()->getOrCreate('d'), self::SHORT, self::ITEM_BODY];
        yield 'Key-value store getOrCreate' => [static fn (ApifyClient $c) => $c->keyValueStores()->getOrCreate('s'), self::SHORT, self::ITEM_BODY];
        yield 'Request queue getOrCreate' => [static fn (ApifyClient $c) => $c->requestQueues()->getOrCreate('q'), self::SHORT, self::ITEM_BODY];
        yield 'Schedule create' => [static fn (ApifyClient $c) => $c->schedules()->create(['name' => 's']), self::SHORT, self::ITEM_BODY];
        yield 'Webhook create' => [static fn (ApifyClient $c) => $c->webhooks()->create(['requestUrl' => 'https://example.com']), self::SHORT, self::ITEM_BODY];
        yield 'Request queue listHead' => [static fn (ApifyClient $c) => $c->requestQueue('q')->listHead(), self::SHORT, '{"data":{"items":[]}}'];
        yield 'Request queue addRequest' => [static fn (ApifyClient $c) => $c->requestQueue('q')->addRequest(new RequestQueueRequest('https://example.com', 'k')), self::SHORT, self::ITEM_BODY];
        yield 'Request queue batchDeleteRequests' => [
            static fn (ApifyClient $c) => $c->requestQueue('q')->batchDeleteRequests([new RequestQueueRequest('https://example.com', 'k', ['id' => 'r1'])]),
            self::SHORT,
            '{"data":{"processedRequests":[],"unprocessedRequests":[]}}',
        ];
        yield 'Request queue deleteRequestLock' => [static fn (ApifyClient $c) => $c->requestQueue('q')->deleteRequestLock('r1'), self::SHORT, ''];
        yield 'Key-value store update' => [static fn (ApifyClient $c) => $c->keyValueStore('s')->update(['title' => 't']), self::LONG, self::ITEM_BODY];
        yield 'Key-value store recordExists' => [static fn (ApifyClient $c) => $c->keyValueStore('s')->recordExists('k'), self::LONG, ''];
        yield 'Key-value store getRecordPublicUrl' => [static fn (ApifyClient $c) => $c->keyValueStore('s')->getRecordPublicUrl('k'), self::LONG, self::ITEM_BODY];
        yield 'Key-value store createKeysPublicUrl' => [static fn (ApifyClient $c) => $c->keyValueStore('s')->createKeysPublicUrl(), self::LONG, self::ITEM_BODY];
        yield 'Dataset createItemsPublicUrl' => [static fn (ApifyClient $c) => $c->dataset('d')->createItemsPublicUrl(), self::LONG, self::ITEM_BODY];
        yield 'Dataset pushItems' => [static fn (ApifyClient $c) => $c->dataset('d')->pushItems([['a' => 1]]), self::MEDIUM, ''];
        yield 'Schedule getLog' => [static fn (ApifyClient $c) => $c->schedule('s')->getLog(), self::MEDIUM, '{"data":[]}'];
    }

    /**
     * @param callable(ApifyClient):mixed $call
     */
    #[DataProvider('tierCases')]
    public function testDefaultTierMatchesTheReferenceClient(callable $call, float $expectedTimeout, string $body): void
    {
        $transport = (new MockTransport())->queueResponse(200, $body);

        $call(new ApifyClient(token: 't', httpClient: $transport));

        // The call's first (and for the public-URL builders, only) request carries the tier's timeout.
        self::assertSame($expectedTimeout, $transport->timeouts[0]);
    }

    public function testPublicUrlBuildersAcceptATimeoutForTheStorageRequest(): void
    {
        $transport = (new MockTransport())
            ->queueResponse(200, self::ITEM_BODY)
            ->queueResponse(200, self::ITEM_BODY)
            ->queueResponse(200, self::ITEM_BODY);
        $client = new ApifyClient(token: 't', httpClient: $transport);

        $client->keyValueStore('s')->getRecordPublicUrl('k', 7);
        $client->keyValueStore('s')->createKeysPublicUrl(null, null, 8);
        $client->dataset('d')->createItemsPublicUrl(null, null, null, 9);

        self::assertSame([7.0, 8.0, 9.0], $transport->timeouts);
    }

    public function testPushItemsDoesNotRetryATimeout(): void
    {
        $transport = (new MockTransport())->queueError(timeout: true)->queueResponse(200, '');
        $client = new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, httpClient: $transport);

        try {
            $client->dataset('d')->pushItems([['a' => 1]]);
            self::fail('a timed-out push must not be retried');
        } catch (\Apify\Client\Exception\TransportException) {
            self::assertSame(1, $transport->callCount());
        }
    }

    public function testOtherRequestsRetryATimeout(): void
    {
        $transport = (new MockTransport())->queueError(timeout: true)->queueResponse(200, Json::encode(['data' => ['id' => 'a']]));
        $client = new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, httpClient: $transport);

        $client->actor('a')->get();

        self::assertSame(2, $transport->callCount());
    }
}
