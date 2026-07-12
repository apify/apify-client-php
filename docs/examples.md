# Runnable examples

Each snippet below assumes a configured `$client` and that the types it uses are imported with the
appropriate `use` statements (see [Namespaces](README.md#namespaces)); the first
[complete program](#a-complete-standalone-program) shows the full scaffolding the shorter snippets
omit for brevity. The complete programs on this page live under
[`tests/Examples/`](../tests/Examples) and are executed end-to-end against the live API by the
`Test examples` CI step (see `ExamplesTest`), so those programs are guaranteed to stay runnable.
Inline snippets on the other documentation pages are not executed: they are only syntax-checked with
`php -l` by `DocSnippetsTest`, which catches parse errors but does not resolve classes or check types.

## A complete, standalone program

The snippet below is a full program you can save as `example.php` and run with `php example.php`
after `composer require apify/apify-client`. It shows the required scaffolding — the `<?php` tag,
loading Composer's autoloader, and the `use` imports — that the shorter snippets in this file omit
for brevity (they assume a configured `$client` and imported types).

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Apify\Client\ApifyClient;
use Apify\Client\Exception\ApifyApiException;

$client = new ApifyClient(getenv('APIFY_TOKEN') ?: 'my-api-token');

try {
    // Run a public store Actor and wait up to 120s for it to finish.
    $run = $client->actor('apify/hello-world')->call(null, null, 120);

    // Read the items the run produced into its default dataset. getDefaultDatasetId() is
    // ?string, so cast it to satisfy dataset(string $id).
    $items = $client->dataset((string) $run->getDefaultDatasetId())->listItems();
    echo 'Item count: ' . $items->getCount() . PHP_EOL;
} catch (ApifyApiException $e) {
    echo 'API error ' . $e->getStatusCode() . ': ' . $e->getApiMessage() . PHP_EOL;
}
```

## Run a store Actor and read its default dataset

```php
$run = $client->actor('apify/hello-world')->call(null, null, 120);
// getDefaultDatasetId() is ?string, so cast it to satisfy dataset(string $id).
$items = $client->dataset((string) $run->getDefaultDatasetId())->listItems();
echo 'Item count: ' . $items->getCount() . PHP_EOL;
```

## Each storage: create, push, read

```php
$dataset = $client->datasets()->getOrCreate('example-ds');
$client->dataset($dataset->getId())->pushItems([['hello' => 'world']]);
$dsItems = $client->dataset($dataset->getId())->listItems();

$store = $client->keyValueStores()->getOrCreate('example-kvs');
$client->keyValueStore($store->getId())->setRecordJson('OUTPUT', ['answer' => 42]);
$record = $client->keyValueStore($store->getId())->getRecord('OUTPUT');

$queue = $client->requestQueues()->getOrCreate('example-rq');
$client->requestQueue($queue->getId())->addRequest(new RequestQueueRequest('https://example.com', 'example'));
$head = $client->requestQueue($queue->getId())->listHead(10);
```

## Get own account details

```php
$user = $client->me()->get();
if ($user !== null) {
    echo 'Account ' . $user->getId() . ' / ' . $user->getUsername() . PHP_EOL;
}
```

## Create a new Actor, build it, run it, wait, and print the finished run log

```php
$created = $client->actors()->create([
    'name' => 'my-example-actor',
    'isPublic' => false,
    'versions' => [[
        'versionNumber' => '0.0',
        'sourceType' => 'SOURCE_FILES',
        'buildTag' => 'latest',
        'sourceFiles' => [
            ['name' => 'Dockerfile', 'format' => 'TEXT', 'content' => "FROM apify/actor-node:20\nCOPY . ./\nCMD node main.js"],
            ['name' => 'main.js', 'format' => 'TEXT', 'content' => "console.log('hi');"],
        ],
    ]],
]);
try {
    $build = $client->actor($created->getId())->build('0.0', new ActorBuildOptions());
    $client->build($build->getId())->waitForFinish(300);
    $run = $client->actor($created->getId())->call(null, null, 120);
    $log = $client->run($run->getId())->log()->get();
    if ($log !== null) {
        echo $log . PHP_EOL;
    }
} finally {
    $client->actor($created->getId())->delete();
}
```

## Start a run, wait, then fetch the Actor's last run and its storages

```php
$started = $client->actor('apify/hello-world')->start();
$client->run($started->getId())->waitForFinish(120);

// Fetch the last run and read its storages via the run-nested convenience accessors.
$lastRun = $client->actor('apify/hello-world')->lastRun(new LastRunOptions(status: 'SUCCEEDED'));
$last = $lastRun->get();
if ($last !== null) {
    $lastRun->dataset()->listItems();
    $lastRun->keyValueStore()->getRecord('OUTPUT');
    $lastRun->requestQueue()->listHead(10);
}
```

## Lazy iteration of Store Actors

```php
$shown = 0;
// The second argument is the per-page (chunk) size; StoreListOptions::limit would cap the total.
foreach ($client->store()->iterate(new StoreListOptions(), 10) as $item) {
    echo $item->getName() . PHP_EOL;
    if (++$shown >= 5) {
        break;
    }
}
```

## Run an Actor with log redirection

```php
// Start the run without waiting, then redirect its log to stdout live. The streaming endpoint keeps
// the connection open and emits log lines in real time; reading to EOF also waits for the run to end.
$run = $client->actor('apify/hello-world')->start();
$stream = $client->run($run->getId())->getStreamedLog();
while (!$stream->eof()) {
    echo $stream->read(8192);
}
```
