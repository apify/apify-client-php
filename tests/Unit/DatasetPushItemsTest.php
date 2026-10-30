<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Covers the item-shape check of {@see \Apify\Client\Resource\DatasetClient::pushItems()} (reference client #1066). */
final class DatasetPushItemsTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    public function testSingleItemAndListOfItemsAreSent(): void
    {
        $transport = (new MockTransport())->queueResponse(201, '')->queueResponse(201, '')->queueResponse(201, '');
        $dataset = $this->client($transport)->dataset('ds1');

        $dataset->pushItems(['a' => 1]);
        self::assertSame('{"a":1}', MockTransport::readBody($transport->lastRequest()));

        $dataset->pushItems([['a' => 1], (object) ['b' => 2]]);
        self::assertSame('[{"a":1},{"b":2}]', MockTransport::readBody($transport->lastRequest()));

        $dataset->pushItems([]);
        self::assertSame('[]', MockTransport::readBody($transport->lastRequest()));
    }

    public function testPreSerializedStringIsSentAsIs(): void
    {
        $transport = (new MockTransport())->queueResponse(201, '');
        $this->client($transport)->dataset('ds1')->pushItems('[{"a":1}]');

        self::assertSame('[{"a":1}]', MockTransport::readBody($transport->lastRequest()));
    }

    /** @return iterable<string,array{array<mixed>|object}> */
    public static function invalidItems(): iterable
    {
        yield 'list with a scalar' => [[1, 2]];
        yield 'list with a nested list' => [[['x'], ['y']]];
        yield 'list mixing objects and scalars' => [[['a' => 1], 'text']];
        yield 'list with null' => [[['a' => 1], null]];
    }

    /** @param array<mixed>|object $items */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidItems')]
    public function testInvalidShapeIsRejectedBeforeAnyRequest(array|object $items): void
    {
        $transport = new MockTransport();

        try {
            $this->client($transport)->dataset('ds1')->pushItems($items);
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('pushItems()', $e->getMessage());
            self::assertSame(0, $transport->callCount());
        }
    }

    public function testObjectIsAccepted(): void
    {
        $transport = (new MockTransport())->queueResponse(201, '');
        $this->client($transport)->dataset('ds1')->pushItems(new stdClass());

        self::assertSame('{}', MockTransport::readBody($transport->lastRequest()));
    }

    public function testTimedOutPushIsNotRetried(): void
    {
        // The API may already have stored the items, so retrying a timeout would duplicate them.
        $transport = (new MockTransport())->queueError(timeout: true)->queueResponse(201, '');

        try {
            $this->client($transport)->dataset('ds1')->pushItems(['a' => 1]);
            self::fail('expected a transport exception');
        } catch (\Apify\Client\Exception\TransportException) {
            self::assertSame(1, $transport->callCount());
        }
    }
}
