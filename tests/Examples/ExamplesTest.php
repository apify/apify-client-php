<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Examples;

use Apify\Client\ApifyClient;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Runs each documentation example end-to-end against the live API, proving the snippets in the docs
 * actually work. Skipped when {@code APIFY_TOKEN} is not set. This is the "Test examples" CI step.
 */
final class ExamplesTest extends TestCase
{
    private function client(): ApifyClient
    {
        $token = getenv('APIFY_TOKEN');
        if ($token === false || $token === '') {
            self::markTestSkipped('skipping: APIFY_TOKEN is not set');
        }
        $apiUrl = getenv('APIFY_API_URL');
        $baseUrl = ($apiUrl === false || $apiUrl === '') ? 'https://api.apify.com' : rtrim($apiUrl, '/');
        if (str_ends_with($baseUrl, '/v2')) {
            $baseUrl = substr($baseUrl, 0, -3);
        }
        return new ApifyClient(token: $token, baseUrl: $baseUrl);
    }

    /**
     * @return array<string,array{class-string}>
     */
    public static function exampleProvider(): array
    {
        return [
            'RunStoreActor' => [RunStoreActor::class],
            'Storages' => [Storages::class],
            'GetAccount' => [GetAccount::class],
            'CreateBuildRunActor' => [CreateBuildRunActor::class],
            'RunAndLastRunStorages' => [RunAndLastRunStorages::class],
            'IterateStore' => [IterateStore::class],
            'LogRedirection' => [LogRedirection::class],
        ];
    }

    /**
     * @param class-string $exampleClass
     * @dataProvider exampleProvider
     */
    public function testExampleRuns(string $exampleClass): void
    {
        $client = $this->client();
        ob_start();
        try {
            /** @var callable $runner */
            $runner = [$exampleClass, 'run'];
            $runner($client);
        } catch (Throwable $e) {
            ob_end_clean();
            self::fail($exampleClass . ' failed: ' . $e->getMessage());
        }
        ob_end_clean();
        self::assertTrue(true);
    }
}
