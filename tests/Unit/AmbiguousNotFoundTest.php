<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Unit;

use Apify\Client\ApifyClient;
use Apify\Client\Exception\ApifyApiException;
use Apify\Client\Exception\ConflictException;
use Apify\Client\Exception\ForbiddenException;
use Apify\Client\Exception\InvalidRequestException;
use Apify\Client\Exception\NotFoundException;
use Apify\Client\Exception\RateLimitException;
use Apify\Client\Exception\ServerException;
use Apify\Client\Exception\UnauthorizedException;
use Apify\Client\Internal\HttpClientCore;
use Apify\Client\Internal\Json;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Covers the status-keyed {@see ApifyApiException} subclasses and the "ambiguous 404" rule: a 404 on
 * a resource addressed by ID resolves to null/no-op, but a 404 on an ID-less chained resource (e.g.
 * {@code run.dataset()}) or a fixed sub-path (e.g. {@code getStatistics()}) is rethrown, since the
 * response cannot tell a missing parent from a missing sub-resource. Matches the reference client's
 * `#1041`/`#1042`.
 */
final class AmbiguousNotFoundTest extends TestCase
{
    private function client(MockTransport $transport): ApifyClient
    {
        return new ApifyClient(token: 't', minDelayBetweenRetriesMillis: 1, timeoutSecs: 5, httpClient: $transport);
    }

    private function notFoundBody(string $type = 'record-not-found'): string
    {
        return Json::encode(['error' => ['type' => $type, 'message' => 'not found']]);
    }

    // ---- Exception subclasses (#1041) ------------------------------------------------------

    /** @return array<string,array{0:int,1:class-string<ApifyApiException>}> */
    public static function statusClassProvider(): array
    {
        return [
            '400' => [400, InvalidRequestException::class],
            '401' => [401, UnauthorizedException::class],
            '403' => [403, ForbiddenException::class],
            '404' => [404, NotFoundException::class],
            '409' => [409, ConflictException::class],
            '429' => [429, RateLimitException::class],
            '500' => [500, ServerException::class],
            '502' => [502, ServerException::class],
        ];
    }

    /** @dataProvider statusClassProvider */
    public function testBuildApiErrorReturnsStatusSpecificSubclass(int $status, string $expectedClass): void
    {
        $error = HttpClientCore::buildApiError($status, $this->notFoundBody('x'), 1, 'GET', '/x');
        self::assertInstanceOf($expectedClass, $error);
        self::assertInstanceOf(ApifyApiException::class, $error, 'every subclass must still satisfy instanceof ApifyApiException');
        self::assertSame($status, $error->getStatusCode());
    }

    public function testUnmappedStatusStaysPlainApifyApiException(): void
    {
        $error = HttpClientCore::buildApiError(418, $this->notFoundBody('x'), 1, 'GET', '/x');
        self::assertSame(ApifyApiException::class, get_class($error));
    }

    // ---- isNotFound() swallows any 404 type, not just the historical allowlist (#1041) ----------

    public function testGetSwallows404OfAnyType(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody('some-other-type-the-allowlist-never-knew-about'));
        $actor = $this->client($transport)->actor('missing')->get();
        self::assertNull($actor);
    }

    // ---- Chained (ID-less) resource clients throw on 404 instead of resolving to null (#1042) ---

    public function testRunDatasetGetThrowsOnAmbiguous404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $this->expectException(NotFoundException::class);
        $this->client($transport)->run('missing-run')->dataset()->get();
    }

    public function testRunDatasetDeleteThrowsOnAmbiguous404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $this->expectException(NotFoundException::class);
        $this->client($transport)->run('missing-run')->dataset()->delete();
    }

    public function testRunKeyValueStoreGetThrowsOnAmbiguous404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $this->expectException(NotFoundException::class);
        $this->client($transport)->run('missing-run')->keyValueStore()->get();
    }

    public function testRunRequestQueueGetThrowsOnAmbiguous404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $this->expectException(NotFoundException::class);
        $this->client($transport)->run('missing-run')->requestQueue()->get();
    }

    public function testRunLogGetThrowsOnAmbiguous404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $this->expectException(NotFoundException::class);
        $this->client($transport)->run('missing-run')->log()->get();
    }

    public function testBuildLogGetThrowsOnAmbiguous404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $this->expectException(NotFoundException::class);
        $this->client($transport)->build('missing-build')->log()->get();
    }

    /** Directly addressed (by-ID) clients are unaffected: get() still resolves to null on a 404. */
    public function testTopLevelDatasetGetStillReturnsNullOn404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $dataset = $this->client($transport)->dataset('missing')->get();
        self::assertNull($dataset);
    }

    /** Directly addressed (by-ID) log client is unaffected: get() still resolves to null on a 404. */
    public function testTopLevelLogGetStillReturnsNullOn404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $log = $this->client($transport)->log('missing')->get();
        self::assertNull($log);
    }

    // ---- Fixed sub-paths of an ID-addressed resource always throw (#1042) -----------------------

    public function testDatasetGetStatisticsThrowsOn404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $this->expectException(NotFoundException::class);
        $this->client($transport)->dataset('missing')->getStatistics();
    }

    public function testTaskGetInputThrowsOn404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $this->expectException(NotFoundException::class);
        $this->client($transport)->task('missing')->getInput();
    }

    public function testScheduleGetLogThrowsOn404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $this->expectException(NotFoundException::class);
        $this->client($transport)->schedule('missing')->getLog();
    }

    public function testBuildGetOpenApiDefinitionThrowsOn404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $this->expectException(NotFoundException::class);
        $this->client($transport)->build('missing')->getOpenApiDefinition();
    }

    /** getRecord() is unaffected by the ambiguous-404 rule: it still resolves to null for a missing key. */
    public function testKeyValueStoreGetRecordStillReturnsNullOn404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $record = $this->client($transport)->keyValueStore('store1')->getRecord('missing-key');
        self::assertNull($record);
    }

    /** getRequest() is unaffected: it still resolves to null for a missing request. */
    public function testRequestQueueGetRequestStillReturnsNullOn404(): void
    {
        $transport = (new MockTransport())->queueResponse(404, $this->notFoundBody());
        $request = $this->client($transport)->requestQueue('q1')->getRequest('missing-id');
        self::assertNull($request);
    }

    // ---- Empty identifiers rejected up front (#1042) ---------------------------------------------

    public function testActorVersionEmptyStringRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client(new MockTransport())->actor('a1')->version('');
    }

    public function testActorEnvVarEmptyNameRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client(new MockTransport())->actor('a1')->version('0.1')->envVar('');
    }

    public function testActorBuildEmptyVersionNumberRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client(new MockTransport())->actor('a1')->build('');
    }
}
