<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Tests\integration;

use Flarum\Group\Group;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Upgrading moves the managed-user lists from settings into the backers table.
 */
class SeedBackersMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-open-collective');

        $this->prepareDatabase([
            Group::class => [
                ['id' => 10, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters'],
                ['id' => 11, 'name_singular' => 'Contributor', 'name_plural' => 'Contributors'],
            ],
            User::class => [
                ['id' => 2, 'username' => 'alice', 'email' => 'alice@example.com'],
                ['id' => 3, 'username' => 'bob', 'email' => 'bob@example.com'],
            ],
            'settings' => [
                ['key' => 'fof-open-collective.group_id', 'value' => '10'],
                ['key' => 'fof-open-collective.onetime_group_id', 'value' => '11'],
                // 99 no longer exists and must be skipped rather than break the foreign key.
                ['key' => 'fof-open-collective.users', 'value' => '[2,99]'],
                ['key' => 'fof-open-collective.onetime_users', 'value' => '[2,3]'],
            ],
        ]);
    }

    protected function migration(): array
    {
        return require __DIR__.'/../../migrations/2026_10_15_000001_seed_fof_open_collective_backers_from_settings.php';
    }

    #[Test]
    public function managed_users_become_backer_rows_owning_their_groups(): void
    {
        $this->app();
        $this->migration()['up']($this->database()->getSchemaBuilder());

        $rows = $this->database()->table('fof_open_collective_backers')->orderBy('user_id')->get();

        $this->assertSame([2, 3], $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([10, 11], json_decode($rows[0]->managed_group_ids, true));
        $this->assertSame([11], json_decode($rows[1]->managed_group_ids, true));
        $this->assertNull($rows[0]->status);
        $this->assertFalse($this->database()->table('settings')->whereIn('key', ['fof-open-collective.users', 'fof-open-collective.onetime_users'])->exists());
    }

    #[Test]
    public function rolling_back_restores_the_settings_lists(): void
    {
        $this->app();
        $this->migration()['up']($this->database()->getSchemaBuilder());
        $this->migration()['down']($this->database()->getSchemaBuilder());

        $setting = fn (string $key) => json_decode($this->database()->table('settings')->where('key', $key)->value('value'), true);

        $this->assertSame([2], $setting('fof-open-collective.users'));
        $this->assertSame([2, 3], $setting('fof-open-collective.onetime_users'));
    }
}
