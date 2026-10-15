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

use Flarum\Audit\AuditLogger;
use FoF\OpenCollective\BackerStatus;
use FoF\OpenCollective\Services\BackerSynchronizer;
use FoF\OpenCollective\Tests\fixtures\FakeOpenCollectiveProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * With flarum/audit enabled, every change the sync makes lands in the audit log.
 */
class AuditIntegrationTest extends SyncTestCase
{
    protected function extensions(): array
    {
        return ['flarum-audit', 'fof-open-collective'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Keeps Audit's own "extension enabled" entry out of the assertions.
        AuditLogger::$testMode = true;

        $this->setting(BackerSynchronizer::BASELINE_SETTING, '2026-01-01T00:00:00+00:00');
    }

    protected function tearDown(): void
    {
        AuditLogger::$testMode = false;

        parent::tearDown();
    }

    /** @return array<int, array{action: string, actor_id: int|null, client: string, payload: array<string, mixed>}> */
    protected function auditEntries(): array
    {
        return $this->database()->table('audit_log')
            ->where('action', 'like', 'fof_open_collective.%')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => ['action' => $row->action, 'actor_id' => $row->actor_id, 'client' => $row->client, 'payload' => json_decode($row->payload, true)])
            ->all();
    }

    #[Test]
    public function a_stopped_subscription_is_logged_with_its_group_move(): void
    {
        $this->known(5, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('CANCELLED', 'MONTHLY', 'dave@example.com')]);

        $this->sync();

        // assertEquals: MySQL's JSON column does not preserve payload key order.
        $this->assertEquals([[
            'action'   => 'fof_open_collective.recurring_stopped',
            'actor_id' => null,
            'client'   => 'cli',
            'payload'  => [
                'user_id'         => 5,
                'previous_status' => 'recurring',
                'current_status'  => 'onetime',
                'old_group_ids'   => [self::RECURRING_GROUP],
                'new_group_ids'   => [self::ONETIME_GROUP],
            ],
        ]], $this->auditEntries());
    }

    #[Test]
    public function each_transition_and_repair_has_its_own_action(): void
    {
        $this->known(3, BackerStatus::OneTime, [self::ONETIME_GROUP]);
        $this->members([[3, self::ONETIME_GROUP]]);
        $this->known(4, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        FakeOpenCollectiveProvider::backers(
            monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com'), $this->order('ACTIVE', 'MONTHLY', 'carol@example.com')],
            onetime: [$this->order('PAID', 'ONETIME', 'dave@example.com')]
        );

        $this->sync();

        $byUser = [];

        foreach ($this->auditEntries() as $entry) {
            $byUser[$entry['payload']['user_id']] = $entry['action'];
        }

        ksort($byUser);

        $this->assertSame([
            2 => 'fof_open_collective.recurring_started',
            3 => 'fof_open_collective.backing_ended',
            4 => 'fof_open_collective.groups_changed',
            5 => 'fof_open_collective.onetime_started',
        ], $byUser);
    }

    #[Test]
    public function a_dry_run_logs_nothing(): void
    {
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $this->sync(['--dry-run' => true]);

        $this->assertSame([], $this->auditEntries());
    }
}
