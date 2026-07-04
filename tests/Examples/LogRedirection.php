<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Examples;

use Apify\Client\ApifyClient;

/** Run an Actor with log redirection turned on (stream the run's log). */
final class LogRedirection
{
    public static function run(ApifyClient $client): void
    {
        // Start the run without waiting for it to finish.
        $run = $client->actor('apify/hello-world')->start();

        // Open a live streaming connection to the run's log (the `stream=1` endpoint) and redirect it
        // to stdout as the run produces it. The server keeps the connection open and emits log lines
        // in real time; the stream ends once the run finishes, so reading it to EOF also waits for
        // the run to complete.
        $stream = $client->run((string) $run->getId())->getStreamedLog();
        while (!$stream->eof()) {
            echo $stream->read(8192);
        }
    }
}
