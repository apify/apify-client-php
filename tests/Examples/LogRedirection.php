<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Examples;

use Apify\Client\ApifyClient;

/** Run an Actor with log redirection turned on (stream the run's log). */
final class LogRedirection
{
    public static function run(ApifyClient $client): void
    {
        $run = $client->actor('apify/hello-world')->start();
        // Wait for the run to finish so the full log is available, then stream it to stdout.
        $client->run((string) $run->getId())->waitForFinish(120);
        $stream = $client->run((string) $run->getId())->getStreamedLog();
        while (!$stream->eof()) {
            echo $stream->read(8192);
        }
    }
}
