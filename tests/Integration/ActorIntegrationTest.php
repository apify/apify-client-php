<?php

declare(strict_types=1);

namespace Apify\Client\Tests\Integration;

use Apify\Client\Model\ActorEnvVar;
use Apify\Client\Options\ActorListOptions;
use Apify\Client\Options\ListOptions;

final class ActorIntegrationTest extends IntegrationTestCase
{
    public function testListActors(): void
    {
        $client = $this->requireClient();
        $page = $client->actors()->list(new ActorListOptions(my: true, limit: 5));
        self::assertLessThanOrEqual(5, count($page->getItems()));
        self::assertSame(count($page->getItems()), $page->getCount());
        self::assertGreaterThanOrEqual(count($page->getItems()), $page->getTotal());
    }

    public function testGetActor(): void
    {
        $client = $this->requireClient();
        $created = $client->actors()->create(self::minimalActor(self::uniqueName('get')));
        try {
            $got = $client->actor((string) $created->getId())->get();
            self::assertNotNull($got);
            self::assertSame($created->getId(), $got->getId());
        } finally {
            $client->actor((string) $created->getId())->delete();
        }
    }

    public function testActorCrudFlow(): void
    {
        $client = $this->requireClient();
        $created = $client->actors()->create(self::minimalActor(self::uniqueName('crud')));
        try {
            $actor = $client->actor((string) $created->getId());
            self::assertNotNull($actor->get());
            $updated = $actor->update(['title' => 'Updated Title']);
            self::assertSame('Updated Title', $updated->getTitle());
            $actor->builds()->list(new ListOptions());
            $actor->versions()->list(new ListOptions());
        } finally {
            $client->actor((string) $created->getId())->delete();
        }
    }

    public function testActorVersionCrudFlow(): void
    {
        $client = $this->requireClient();
        $created = $client->actors()->create(self::minimalActor(self::uniqueName('ver')));
        try {
            $actor = $client->actor((string) $created->getId());
            $version = $actor->versions()->create([
                'versionNumber' => '0.1',
                'sourceType' => 'SOURCE_FILES',
                'buildTag' => 'latest',
                'sourceFiles' => [],
            ]);
            self::assertSame('0.1', $version->getVersionNumber());
            self::assertNotNull($actor->version('0.1')->get());
            $actor->versions()->list(new ListOptions());
            $actor->version('0.1')->update([
                'buildTag' => 'beta',
                'sourceType' => 'SOURCE_FILES',
                'sourceFiles' => [],
            ]);
            $actor->version('0.1')->delete();
        } finally {
            $client->actor((string) $created->getId())->delete();
        }
    }

    public function testValidateInput(): void
    {
        $client = $this->requireClient();
        // apify/hello-world is a public store Actor; validate-input is read-only and returns
        // {"valid": <bool>}. A well-formed input validates true.
        self::assertTrue($client->actor('apify/hello-world')->validateInput(['firstNumber' => 1]));
    }

    public function testActorEnvVarCrudFlow(): void
    {
        $client = $this->requireClient();
        $created = $client->actors()->create(self::minimalActor(self::uniqueName('env')));
        try {
            $actor = $client->actor((string) $created->getId());
            $envVars = $actor->version('0.0')->envVars();
            $envVars->create(new ActorEnvVar('MY_VAR', 'value1'));
            self::assertNotNull($actor->version('0.0')->envVar('MY_VAR')->get());
            $envVars->list();
            $actor->version('0.0')->envVar('MY_VAR')->update(new ActorEnvVar('MY_VAR', 'value2'));
            $actor->version('0.0')->envVar('MY_VAR')->delete();
        } finally {
            $client->actor((string) $created->getId())->delete();
        }
    }
}
