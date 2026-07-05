<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Examples;

use PHPUnit\Framework\TestCase;

/**
 * Validates that every fenced {@code ```php} snippet in the docs (and README) is syntactically valid,
 * runnable PHP. Each block is wrapped in a minimal harness (autoload + imports + a {@code $client})
 * and checked with {@code php -l}, so a broken or mis-formatted snippet fails CI. Runs offline.
 */
final class DocSnippetsTest extends TestCase
{
    /**
     * @return array<string,array{string,int,string}>
     */
    public static function snippetProvider(): array
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/docs/*.md') ?: [],
            [$root . '/README.md'],
        );

        $cases = [];
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match_all('/```php\n(.*?)```/s', $contents, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as $i => [$code]) {
                    $name = basename($file) . '#' . ($i + 1);
                    $cases[$name] = [$code, $i + 1, basename($file)];
                }
            }
        }
        return $cases;
    }

    /**
     * @dataProvider snippetProvider
     */
    public function testSnippetIsValidPhp(string $code, int $index, string $file): void
    {
        // A snippet that already opens with `<?php` is a complete, standalone program (its own
        // autoload + imports) — lint it verbatim. Otherwise wrap it in the shared harness.
        if (str_starts_with(ltrim($code), '<?php')) {
            $harness = $code;
        } else {
            $harness = "<?php\ndeclare(strict_types=1);\n"
                . 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
                . $this->imports()
                . "\$client = new \\Apify\\Client\\ApifyClient('token');\n"
                . $code . "\n";
        }

        $tmp = tempnam(sys_get_temp_dir(), 'apify_snippet_') . '.php';
        file_put_contents($tmp, $harness);
        try {
            $output = [];
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $exit);
            self::assertSame(0, $exit, sprintf("snippet %s#%d is not valid PHP:\n%s", $file, $index, implode("\n", $output)));
        } finally {
            @unlink($tmp);
        }
    }

    /** Imports for the short class names used across the docs, so snippets can use them directly. */
    private function imports(): string
    {
        $classes = [
            'Apify\Client\ApifyClient',
            'Apify\Client\Version',
            'Apify\Client\Model\RequestQueueRequest',
            'Apify\Client\Model\ActorEnvVar',
            'Apify\Client\Exception\ApifyApiException',
            'Apify\Client\Http\GuzzleHttpClient',
            'Apify\Client\Http\Psr18HttpClient',
            'Apify\Client\Options\ActorListOptions',
            'Apify\Client\Options\ActorStartOptions',
            'Apify\Client\Options\ActorBuildOptions',
            'Apify\Client\Options\ListOptions',
            'Apify\Client\Options\StorageListOptions',
            'Apify\Client\Options\StoreListOptions',
            'Apify\Client\Options\RunListOptions',
            'Apify\Client\Options\DatasetListItemsOptions',
            'Apify\Client\Options\DatasetDownloadOptions',
            'Apify\Client\Options\DownloadItemsFormat',
            'Apify\Client\Options\ListKeysOptions',
            'Apify\Client\Options\GetRecordOptions',
            'Apify\Client\Options\SetRecordOptions',
            'Apify\Client\Options\ListRequestsOptions',
            'Apify\Client\Options\PaginateRequestsOptions',
            'Apify\Client\Options\RequestQueueClientOptions',
            'Apify\Client\Options\LastRunOptions',
            'Apify\Client\Options\LogOptions',
            'Apify\Client\Options\MetamorphOptions',
            'Apify\Client\Options\RunResurrectOptions',
            'Apify\Client\Options\RunChargeOptions',
            'Apify\Client\Options\ValidateInputOptions',
            'Apify\Client\Options\BatchAddRequestsOptions',
        ];
        return implode('', array_map(static fn (string $c): string => "use $c;\n", $classes));
    }
}
