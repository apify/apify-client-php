<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Internal\Json;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** An {@code expiresInSecs} of a signed public URL must be positive. Zero would mean "never expires", and a negative value gives an expired URL. */
final class PublicUrlExpiryTest extends TestCase
{
    /** @return iterable<string,array{int}> */
    public static function nonPositiveExpiries(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-60];
    }

    #[DataProvider('nonPositiveExpiries')]
    public function testItemsUrlRejectsANonPositiveExpiryWithoutARequest(int $expiresInSecs): void
    {
        $transport = new MockTransport();
        $client = new ApifyClient(token: 't', httpClient: $transport);

        try {
            $client->dataset('d')->createItemsPublicUrl(null, $expiresInSecs);
            self::fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('expiresInSecs', $e->getMessage());
        }
        self::assertSame(0, $transport->callCount());
    }

    #[DataProvider('nonPositiveExpiries')]
    public function testKeysUrlRejectsANonPositiveExpiryWithoutARequest(int $expiresInSecs): void
    {
        $transport = new MockTransport();
        $client = new ApifyClient(token: 't', httpClient: $transport);

        try {
            $client->keyValueStore('s')->createKeysPublicUrl(null, $expiresInSecs);
            self::fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('expiresInSecs', $e->getMessage());
        }
        self::assertSame(0, $transport->callCount());
    }

    public function testAPositiveExpiryStillSignsTheUrl(): void
    {
        $store = Json::encode(['data' => ['id' => 'd1', 'urlSigningSecretKey' => 'secret']]);
        $transport = (new MockTransport())->queueResponse(200, $store)->queueResponse(200, $store);
        $client = new ApifyClient(token: 't', httpClient: $transport);

        $items = $client->dataset('d1')->createItemsPublicUrl(null, 60);
        $keys = $client->keyValueStore('d1')->createKeysPublicUrl(null, 60);

        self::assertStringContainsString('signature=', $items);
        self::assertStringContainsString('signature=', $keys);
    }

    public function testOmittingTheExpiryStillGivesANonExpiringSignedUrl(): void
    {
        $transport = (new MockTransport())->queueResponse(200, Json::encode(['data' => ['id' => 'd1', 'urlSigningSecretKey' => 'secret']]));
        $client = new ApifyClient(token: 't', httpClient: $transport);

        self::assertStringContainsString('signature=', $client->dataset('d1')->createItemsPublicUrl());
    }
}
