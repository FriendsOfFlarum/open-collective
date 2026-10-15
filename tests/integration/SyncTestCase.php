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

use Carbon\Carbon;
use Flarum\Extend;
use Flarum\Group\Group;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\User\User;
use FoF\OpenCollective\Backer;
use FoF\OpenCollective\BackerStatus;
use FoF\OpenCollective\Event\BackerGroupsChanged;
use FoF\OpenCollective\Event\BackerMembershipAdopted;
use FoF\OpenCollective\Event\BackerStatusChanged;
use FoF\OpenCollective\Tests\fixtures\FakeOpenCollectiveProvider;
use FoF\OpenCollective\Tests\fixtures\FakeOtherSupporterSource;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Shared fixtures for running the sync command: two groups, four users, faked
 * Open Collective responses and a record of the events fired.
 */
abstract class SyncTestCase extends ConsoleTestCase
{
    protected const RECURRING_GROUP = 10;
    protected const ONETIME_GROUP = 11;

    /** @var array<int, object> */
    protected array $events = [];

    /** @return array<int, string> */
    protected function extensions(): array
    {
        return ['fof-open-collective'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension(...$this->extensions());
        $this->extend((new Extend\ServiceProvider())->register(FakeOpenCollectiveProvider::class)->register(FakeOtherSupporterSource::class));
        FakeOpenCollectiveProvider::reset();
        FakeOtherSupporterSource::$wants = [];

        $this->setting('fof-open-collective.api_key', 'token');
        $this->setting('fof-open-collective.slug', 'test');
        $this->setting('fof-open-collective.group_id', (string) self::RECURRING_GROUP);
        $this->setting('fof-open-collective.onetime_group_id', (string) self::ONETIME_GROUP);

        $this->prepareDatabase([
            Group::class => [
                ['id' => self::RECURRING_GROUP, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters'],
                ['id' => self::ONETIME_GROUP, 'name_singular' => 'Contributor', 'name_plural' => 'Contributors'],
            ],
            User::class => [
                ['id' => 2, 'username' => 'alice', 'email' => 'alice@example.com', 'is_email_confirmed' => 1],
                ['id' => 3, 'username' => 'bob', 'email' => 'bob@example.com', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'carol', 'email' => 'carol@example.com', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'dave', 'email' => 'dave@example.com', 'is_email_confirmed' => 1],
            ],
        ]);
    }

    protected function order(string $status, string $frequency, ?string $email, bool $incognito = false): array
    {
        return [
            'status'      => $status,
            'frequency'   => $frequency,
            'tier'        => ['name' => 'Backer'],
            'fromAccount' => ['id' => 'acct-'.($email ?? uniqid()), 'isIncognito' => $incognito, 'email' => $email],
        ];
    }

    /**
     * A backer the extension already knows about.
     *
     * @param array<int> $managedGroupIds Groups the extension added them to
     */
    protected function known(int $userId, ?BackerStatus $status, array $managedGroupIds): void
    {
        $this->prepareDatabase([
            Backer::class => [['user_id' => $userId, 'status' => $status?->value, 'managed_group_ids' => $managedGroupIds, 'status_changed_at' => $status ? Carbon::now() : null]],
        ]);
    }

    /**
     * @param array<int, array{0: int, 1: int}> $memberships [user ID, group ID] pairs
     */
    protected function members(array $memberships): void
    {
        $this->prepareDatabase([
            'group_user' => array_map(fn ($pair) => ['user_id' => $pair[0], 'group_id' => $pair[1]], $memberships),
        ]);
    }

    protected function sync(array $options = []): string
    {
        $this->app()->getContainer()->make(Dispatcher::class)->listen(
            [BackerStatusChanged::class, BackerGroupsChanged::class, BackerMembershipAdopted::class],
            fn (object $event) => $this->events[] = $event
        );

        return $this->runCommand(['command' => 'fof:open-collective:update'] + $options);
    }

    protected function inGroup(int $userId, int $groupId): bool
    {
        return $this->database()->table('group_user')->where(['user_id' => $userId, 'group_id' => $groupId])->exists();
    }

    /** @return array<int, string> e.g. ["RecurringBackingStarted:2"] */
    protected function eventLog(): array
    {
        return array_map(fn ($event) => (new \ReflectionClass($event))->getShortName().':'.$event->user->id, $this->events);
    }
}
