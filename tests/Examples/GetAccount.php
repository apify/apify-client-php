<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Examples;

use Apify\Client\ApifyClient;

/** Get own account details. */
final class GetAccount
{
    public static function run(ApifyClient $client): void
    {
        $user = $client->me()->get();
        if ($user !== null) {
            echo 'Account ' . $user->getId() . ' / ' . $user->getUsername() . PHP_EOL;
        }
    }
}
