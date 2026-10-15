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

use Flarum\Extend;
use Flarum\Group\Group;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\User\User;
use FoF\OpenCollective\Event\BackerAdded;
use FoF\OpenCollective\Event\BackerRemoved;
use FoF\OpenCollective\Tests\fixtures\FakeOpenCollectiveProvider;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs the sync command end to end against a real database. Only Open
 * Collective's HTTP responses are faked.
 */
class UpdateCommandTest extends ConsoleTestCase
{
    protected const RECURRING_GROUP = 10;
    protected const ONETIME_GROUP = 11;

    /** @var array<int, object> */
    protected array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-open-collective');
        $this->extend((new Extend\ServiceProvider())->register(FakeOpenCollectiveProvider::class));
        FakeOpenCollectiveProvider::reset();

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

    protected function order(string $status, string $frequency, ?string $email): array
    {
        return [
            'status'      => $status,
            'frequency'   => $frequency,
            'tier'        => ['name' => 'Backer'],
            'fromAccount' => ['id' => 'acct-'.($email ?? uniqid()), 'email' => $email],
        ];
    }

    /**
     * @param array<int, int> $recurring user IDs the extension already manages in the recurring group
     * @param array<int, int> $onetime   user IDs the extension already manages in the one-time group
     */
    protected function managed(array $recurring = [], array $onetime = []): void
    {
        $this->setting('fof-open-collective.users', json_encode($recurring));
        $this->setting('fof-open-collective.onetime_users', json_encode($onetime));
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
            [BackerAdded::class, BackerRemoved::class],
            fn (object $event) => $this->events[] = $event
        );

        return $this->runCommand(['command' => 'fof:open-collective:update'] + $options);
    }

    protected function inGroup(int $userId, int $groupId): bool
    {
        return $this->database()->table('group_user')->where(['user_id' => $userId, 'group_id' => $groupId])->exists();
    }

    /** @return array<int, int> */
    protected function managedUsers(string $key = 'users'): array
    {
        return array_map('intval', json_decode($this->database()->table('settings')->where('key', "fof-open-collective.$key")->value('value') ?? '[]', true));
    }

    /** @return array<int, string> e.g. ["added:2", "removed:5"] */
    protected function eventLog(): array
    {
        return array_map(fn ($event) => ($event instanceof BackerAdded ? 'added:' : 'removed:').$event->user->id, $this->events);
    }

    #[Test]
    public function backers_are_added_to_their_groups_and_recorded_as_managed(): void
    {
        FakeOpenCollectiveProvider::backers(
            monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')],
            onetime: [$this->order('PAID', 'ONETIME', 'bob@example.com')]
        );

        $this->sync();

        $this->assertTrue($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertTrue($this->inGroup(3, self::ONETIME_GROUP));
        $this->assertSame([2], $this->managedUsers('users'));
        $this->assertSame([3], $this->managedUsers('onetime_users'));
        $this->assertEqualsCanonicalizing(['added:2', 'added:3'], $this->eventLog());
    }

    #[Test]
    public function the_added_event_carries_the_backer_account(): void
    {
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $this->sync();

        $this->assertSame('alice@example.com', $this->events[0]->backerData->email ?? null);
    }

    #[Test]
    public function a_removal_only_run_drops_the_user_from_the_managed_list(): void
    {
        $this->managed(recurring: [2, 5]);
        $this->members([[2, self::RECURRING_GROUP], [5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $this->sync();

        $this->assertFalse($this->inGroup(5, self::RECURRING_GROUP));
        $this->assertSame([2], $this->managedUsers('users'));
        $this->assertSame(['removed:5'], $this->eventLog());
    }

    #[Test]
    public function members_added_by_hand_are_never_removed(): void
    {
        $this->members([[4, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(4, self::RECURRING_GROUP));
    }

    #[Test]
    public function a_cancelled_subscriber_moves_to_the_onetime_group_without_a_removed_event(): void
    {
        $this->managed(recurring: [5]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('CANCELLED', 'MONTHLY', 'dave@example.com')]);

        $this->sync();

        $this->assertFalse($this->inGroup(5, self::RECURRING_GROUP));
        $this->assertTrue($this->inGroup(5, self::ONETIME_GROUP));
        $this->assertSame(['added:5'], $this->eventLog());
    }

    #[Test]
    public function emails_are_matched_case_insensitively(): void
    {
        $this->managed(recurring: [2]);
        $this->members([[2, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'Alice@Example.COM')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertSame([], $this->eventLog());
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $this->managed(recurring: [5]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $output = $this->sync(['--dry-run' => true]);

        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertFalse($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertTrue($this->inGroup(5, self::RECURRING_GROUP));
        $this->assertSame([5], $this->managedUsers('users'));
        $this->assertSame([], $this->eventLog());
    }

    #[Test]
    public function a_token_missing_a_scope_changes_nothing_and_says_why(): void
    {
        $this->managed(recurring: [5]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::reset([
            new Response(200, [], json_encode(['data' => ['collective' => ['name' => 'Test Collective', 'slug' => 'test']]])),
            new Response(200, [], json_encode([
                'errors' => [['message' => 'The Personal Token is not allowed for operations in scope "orders".', 'extensions' => ['code' => 'Forbidden']]],
                'data'   => null,
            ])),
        ]);

        $output = $this->sync();

        $this->assertStringContainsString('missing a required scope', $output);
        $this->assertTrue($this->inGroup(5, self::RECURRING_GROUP));
    }

    #[Test]
    public function hidden_emails_change_nothing_and_say_why(): void
    {
        $this->managed(recurring: [5]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', null)]);

        $output = $this->sync();

        $this->assertStringContainsString('cannot read backer emails', $output);
        $this->assertTrue($this->inGroup(5, self::RECURRING_GROUP));
    }

    #[Test]
    public function no_backers_at_all_needs_force_to_remove_managed_members(): void
    {
        $this->managed(recurring: [5]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers();

        $this->assertStringContainsString('--force', $this->sync());
        $this->assertTrue($this->inGroup(5, self::RECURRING_GROUP));
    }

    #[Test]
    public function no_backers_at_all_with_force_removes_managed_members(): void
    {
        $this->managed(recurring: [5]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers();

        $this->sync(['--force' => true]);

        $this->assertFalse($this->inGroup(5, self::RECURRING_GROUP));
    }

    #[Test]
    public function log_lines_carry_hours_and_minutes_once(): void
    {
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $output = $this->sync();

        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2} \d{2}, \d{4} @ \d{2}:\d{2} [AP]M \| Retrieving/m', $output);
        $this->assertDoesNotMatchRegularExpression('/@ \d{2}:\d{2} [AP]M \| [A-Z][a-z]{2} \d{2}, \d{4} @/', $output);
    }
}
