<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Examples;

use Apify\Client\ApifyClient;
use Apify\Client\Options\ActorBuildOptions;

/** Create a new Actor, build it, run it, wait, and print the finished run log. */
final class CreateBuildRunActor
{
    public static function run(ApifyClient $client): void
    {
        $created = $client->actors()->create([
            'name' => 'php-example-actor-' . bin2hex(random_bytes(4)),
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
            $build = $client->actor((string) $created->getId())->build('0.0', new ActorBuildOptions());
            $client->build((string) $build->getId())->waitForFinish(300);
            $run = $client->actor((string) $created->getId())->call(null, null, 120);
            $log = $client->run((string) $run->getId())->log()->get();
            if ($log !== null) {
                echo $log . PHP_EOL;
            }
        } finally {
            $client->actor((string) $created->getId())->delete();
        }
    }
}
