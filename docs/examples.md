# Runnable examples

Each example below is a self-contained snippet assuming a configured `$client`. The same programs
live under [`tests/Examples/`](../tests/Examples) and are executed end-to-end against the live API by
the `Test examples` CI step (see `ExamplesTest`), so they are guaranteed to stay runnable.

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

    // Read the items the run produced into its default dataset.
    $items = $client->dataset($run->getDefaultDatasetId())->listItems();
    echo 'Item count: ' . $items->getCount() . PHP_EOL;
} catch (ApifyApiException $e) {
    echo 'API error ' . $e->getStatusCode() . ': ' . $e->getApiMessage() . PHP_EOL;
}
```

## Run a store Actor and read its default dataset

```php
$run = $client->actor('apify/hello-world')->call(null, null, 120);
$items = $client->dataset($run->getDefaultDatasetId())->listItems();
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
$client->actor('apify/hello-world')->call(null, null, 120);
$last = $client->actor('apify/hello-world')->lastRun(new LastRunOptions(status: 'SUCCEEDED'))->get();
if ($last !== null) {
    $client->dataset($last->getDefaultDatasetId())->listItems();
    $client->keyValueStore($last->getDefaultKeyValueStoreId())->getRecord('OUTPUT');
}
```

## Lazy iteration of Store Actors

```php
$shown = 0;
foreach ($client->store()->iterate(new StoreListOptions(limit: 10)) as $item) {
    echo $item->getName() . PHP_EOL;
    if (++$shown >= 5) {
        break;
    }
}
```

## Run an Actor with log redirection

```php
$run = $client->actor('apify/hello-world')->start();
$client->run($run->getId())->waitForFinish(120);
$stream = $client->run($run->getId())->getStreamedLog();
while (!$stream->eof()) {
    echo $stream->read(8192);
}
```
