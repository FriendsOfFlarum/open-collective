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

use FoF\OpenCollective\Backer;
use FoF\OpenCollective\BackerStatus;
use FoF\OpenCollective\Event\RecurringBackingStopped;
use FoF\OpenCollective\Services\BackerGroupClaims;
use FoF\OpenCollective\Services\BackerSynchronizer;
use FoF\OpenCollective\Services\SyncStatus;
use FoF\OpenCollective\Tests\fixtures\FakeOpenCollectiveProvider;
use FoF\OpenCollective\Tests\fixtures\FakeOtherSupporterSource;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs the sync command end to end against a real database. Only Open
 * Collective's HTTP responses are faked.
 */
class UpdateCommandTest extends SyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Most tests describe an established install; the baseline run has its own tests.
        $this->setting(BackerSynchronizer::BASELINE_SETTING, '2026-01-01T00:00:00+00:00');
    }

    #[Test]
    public function a_new_recurring_backer_joins_the_recurring_group(): void
    {
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertSame(['RecurringBackingStarted:2'], $this->eventLog());
        $this->assertSame(BackerStatus::Recurring, Backer::find(2)->status);
        $this->assertSame([self::RECURRING_GROUP], Backer::find(2)->managedGroupIds());
    }

    #[Test]
    public function a_new_onetime_backer_joins_the_onetime_group(): void
    {
        FakeOpenCollectiveProvider::backers(onetime: [$this->order('PAID', 'ONETIME', 'bob@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(3, self::ONETIME_GROUP));
        $this->assertSame(['OneTimeBackingStarted:3'], $this->eventLog());
    }

    #[Test]
    public function status_events_carry_the_transition_account_and_groups(): void
    {
        $this->known(5, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('CANCELLED', 'MONTHLY', 'dave@example.com')]);

        $this->sync();

        $event = $this->events[0];
        $this->assertInstanceOf(RecurringBackingStopped::class, $event);
        $this->assertSame(BackerStatus::Recurring, $event->previous);
        $this->assertSame(BackerStatus::OneTime, $event->current);
        $this->assertSame('dave@example.com', $event->account->email ?? null);
        $this->assertSame([self::RECURRING_GROUP], $event->oldGroupIds);
        $this->assertSame([self::ONETIME_GROUP], $event->newGroupIds);
    }

    #[Test]
    public function a_cancelled_subscriber_moves_to_the_onetime_group(): void
    {
        $this->known(5, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('CANCELLED', 'MONTHLY', 'dave@example.com')]);

        $this->sync();

        $this->assertFalse($this->inGroup(5, self::RECURRING_GROUP));
        $this->assertTrue($this->inGroup(5, self::ONETIME_GROUP));
        $this->assertSame(['RecurringBackingStopped:5'], $this->eventLog());
    }

    #[Test]
    public function a_onetime_backer_who_subscribes_moves_to_the_recurring_group(): void
    {
        $this->known(3, BackerStatus::OneTime, [self::ONETIME_GROUP]);
        $this->members([[3, self::ONETIME_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'bob@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(3, self::RECURRING_GROUP));
        $this->assertFalse($this->inGroup(3, self::ONETIME_GROUP));
        $this->assertSame(['RecurringBackingStarted:3'], $this->eventLog());
        $this->assertSame(BackerStatus::OneTime, $this->events[0]->previous);
    }

    #[Test]
    public function a_backer_who_disappears_ends_their_backing_and_is_forgotten(): void
    {
        $this->known(3, BackerStatus::OneTime, [self::ONETIME_GROUP]);
        $this->members([[3, self::ONETIME_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $this->sync();

        $this->assertFalse($this->inGroup(3, self::ONETIME_GROUP));
        $this->assertContains('BackingEnded:3', $this->eventLog());
        $this->assertNull(Backer::find(3));
    }

    #[Test]
    public function a_hand_added_supporter_who_stops_keeps_supporter_and_is_not_also_made_contributor(): void
    {
        // Known as recurring from before adoption existed, so the membership is not owned.
        $this->known(4, BackerStatus::Recurring, []);
        $this->members([[4, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('CANCELLED', 'MONTHLY', 'carol@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(4, self::RECURRING_GROUP));
        $this->assertFalse($this->inGroup(4, self::ONETIME_GROUP));
        $this->assertSame(['RecurringBackingStopped:4'], $this->eventLog());
    }

    #[Test]
    public function a_backer_added_by_hand_is_adopted(): void
    {
        $this->members([[4, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'carol@example.com')]);

        $output = $this->sync();

        $this->assertStringContainsString('#4 carol: none → recurring, took over Supporter', $output);
        $this->assertSame([self::RECURRING_GROUP], Backer::find(4)->managedGroupIds());
        $this->assertSame(['RecurringBackingStarted:4', 'BackerMembershipAdopted:4'], $this->eventLog());
        $this->assertSame([self::RECURRING_GROUP], $this->events[1]->groupIds);
    }

    #[Test]
    public function an_adopted_membership_is_moved_when_the_backing_stops(): void
    {
        $this->members([[4, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'carol@example.com')]);
        $this->sync();

        FakeOpenCollectiveProvider::backers(monthly: [$this->order('CANCELLED', 'MONTHLY', 'carol@example.com')]);
        $this->runCommand(['command' => 'fof:open-collective:update']);

        $this->assertFalse($this->inGroup(4, self::RECURRING_GROUP));
        $this->assertTrue($this->inGroup(4, self::ONETIME_GROUP));
    }

    #[Test]
    public function a_new_recurring_backer_leaves_the_onetime_group_even_if_added_by_hand(): void
    {
        $this->members([[4, self::ONETIME_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'carol@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(4, self::RECURRING_GROUP));
        $this->assertFalse($this->inGroup(4, self::ONETIME_GROUP));
    }

    #[Test]
    public function a_recurring_membership_another_source_still_wants_is_kept(): void
    {
        FakeOtherSupporterSource::$wants = [self::RECURRING_GROUP => [5]];
        $this->known(5, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('CANCELLED', 'MONTHLY', 'dave@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(5, self::RECURRING_GROUP));
        $this->assertFalse($this->inGroup(5, self::ONETIME_GROUP));
        $this->assertSame([], Backer::find(5)->managedGroupIds(), 'ownership passes to the source that still wants it');
        $this->assertSame(['RecurringBackingStopped:5'], $this->eventLog());
    }

    #[Test]
    public function a_onetime_backer_another_source_has_as_recurring_is_not_put_in_the_onetime_group(): void
    {
        FakeOtherSupporterSource::$wants = [self::RECURRING_GROUP => [3]];
        FakeOpenCollectiveProvider::backers(onetime: [$this->order('PAID', 'ONETIME', 'bob@example.com')]);

        $this->sync();

        $this->assertFalse($this->inGroup(3, self::ONETIME_GROUP));
        $this->assertSame(BackerStatus::OneTime, Backer::find(3)->status);
    }

    #[Test]
    public function a_onetime_membership_another_source_still_wants_is_kept(): void
    {
        FakeOtherSupporterSource::$wants = [self::ONETIME_GROUP => [3]];
        $this->known(3, BackerStatus::OneTime, [self::ONETIME_GROUP]);
        $this->members([[3, self::ONETIME_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(3, self::ONETIME_GROUP));
        $this->assertNull(Backer::find(3));
        $this->assertContains('BackingEnded:3', $this->eventLog());
    }

    #[Test]
    public function another_sources_onetime_claim_is_dropped_by_that_source_and_then_cleared(): void
    {
        // Round 1: the other source still claims the one-time membership, so it stays.
        FakeOtherSupporterSource::$wants = [self::ONETIME_GROUP => [2]];
        $this->members([[2, self::ONETIME_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertTrue($this->inGroup(2, self::ONETIME_GROUP));

        // Round 2: seeing the recurring membership, the other source stops claiming it,
        // as BackerGroupClaims does, and the one-time membership goes.
        FakeOtherSupporterSource::$wants = [];
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);
        $this->runCommand(['command' => 'fof:open-collective:update']);

        $this->assertTrue($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertFalse($this->inGroup(2, self::ONETIME_GROUP));
    }

    #[Test]
    public function a_source_mapping_the_groups_the_other_way_round_causes_no_tug_of_war(): void
    {
        // The other source treats this extension's one-time group as its monthly group
        // and has put alice there; this extension has alice as a recurring backer.
        FakeOtherSupporterSource::$wants = [self::ONETIME_GROUP => [2]];
        $this->members([[2, self::ONETIME_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);
        $this->sync();

        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);
        $second = $this->runCommand(['command' => 'fof:open-collective:update']);

        // Both memberships stay, and a second run changes nothing: no battle.
        $this->assertTrue($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertTrue($this->inGroup(2, self::ONETIME_GROUP));
        $this->assertStringContainsString('No changes needed', $second);
    }

    #[Test]
    public function a_onetime_backer_already_in_the_recurring_group_is_not_claimed_for_the_onetime_group(): void
    {
        $this->known(2, BackerStatus::OneTime, []);
        $this->known(3, BackerStatus::OneTime, []);
        $this->members([[2, self::RECURRING_GROUP]]);

        $claims = $this->app()->getContainer()->make(BackerGroupClaims::class);

        $this->assertSame([3], $claims([2, 3], self::ONETIME_GROUP));
    }

    #[Test]
    public function this_extension_tells_other_sources_whom_it_wants_in_each_group(): void
    {
        $this->known(2, BackerStatus::Recurring, []);
        $this->known(3, BackerStatus::OneTime, []);
        $this->known(4, null, [self::RECURRING_GROUP]);

        $claims = $this->app()->getContainer()->make(BackerGroupClaims::class);

        $this->assertSame([2], $claims([2, 3, 4], self::RECURRING_GROUP));
        $this->assertSame([3], $claims([2, 3, 4], self::ONETIME_GROUP));
        $this->assertSame([], $claims([2, 3, 4], 999));
        $this->assertContains(BackerGroupClaims::class, array_map(fn ($c) => $c::class, iterator_to_array($this->app()->getContainer()->tagged(BackerGroupClaims::TAG))));
    }

    #[Test]
    public function a_non_backer_added_by_hand_is_left_alone(): void
    {
        $this->members([[4, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(4, self::RECURRING_GROUP));
        $this->assertNull(Backer::find(4));
    }

    #[Test]
    public function a_stale_membership_record_is_forgotten_without_being_reported(): void
    {
        $this->known(5, null, [self::RECURRING_GROUP]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $output = $this->sync();

        $this->assertStringNotContainsString('#5 dave', $output);
        $this->assertNull(Backer::find(5));
        $this->assertSame(['RecurringBackingStarted:2'], $this->eventLog());
    }

    #[Test]
    public function a_backer_missing_from_their_group_is_restored_with_a_groups_event(): void
    {
        $this->known(2, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertSame(['BackerGroupsChanged:2'], $this->eventLog());
    }

    #[Test]
    public function with_one_group_for_both_a_stopped_subscription_changes_status_only(): void
    {
        $this->setting('fof-open-collective.onetime_group_id', (string) self::RECURRING_GROUP);
        $this->known(5, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('CANCELLED', 'MONTHLY', 'dave@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(5, self::RECURRING_GROUP));
        $this->assertSame(['RecurringBackingStopped:5'], $this->eventLog());
    }

    #[Test]
    public function without_a_onetime_group_a_stopped_subscription_just_leaves_the_recurring_group(): void
    {
        $this->setting('fof-open-collective.onetime_group_id', '');
        $this->known(5, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('CANCELLED', 'MONTHLY', 'dave@example.com')]);

        $this->sync();

        $this->assertFalse($this->inGroup(5, self::RECURRING_GROUP));
        $this->assertFalse($this->inGroup(5, self::ONETIME_GROUP));
        $this->assertSame(BackerStatus::OneTime, Backer::find(5)->status);
        $this->assertSame(['RecurringBackingStopped:5'], $this->eventLog());
    }

    #[Test]
    public function emails_are_matched_case_insensitively(): void
    {
        $this->known(2, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[2, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'Alice@Example.COM')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertSame([], $this->eventLog());
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $this->known(5, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $output = $this->sync(['--dry-run' => true]);

        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('#2 alice: none → recurring, +Supporter', $output);
        $this->assertFalse($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertTrue($this->inGroup(5, self::RECURRING_GROUP));
        $this->assertSame(BackerStatus::Recurring, Backer::find(5)->status);
        $this->assertNull(Backer::find(2));
        $this->assertSame([], $this->eventLog());
    }

    #[Test]
    public function the_first_sync_records_statuses_without_status_events(): void
    {
        $this->setting(BackerSynchronizer::BASELINE_SETTING, '');
        $this->known(2, null, [self::RECURRING_GROUP]);
        $this->members([[3, self::ONETIME_GROUP]]);
        FakeOpenCollectiveProvider::backers(
            monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')],
            onetime: [$this->order('PAID', 'ONETIME', 'bob@example.com')]
        );

        $output = $this->sync();

        $this->assertStringContainsString('First sync', $output);
        $this->assertTrue($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertSame(BackerStatus::Recurring, Backer::find(2)->status);
        $this->assertSame(BackerStatus::OneTime, Backer::find(3)->status);
        // No status events, but restoring alice's membership and adopting bob's
        // hand-added one are still recorded.
        $this->assertSame(['BackerGroupsChanged:2', 'BackerMembershipAdopted:3'], $this->eventLog());
        $this->assertNotEmpty($this->database()->table('settings')->where('key', BackerSynchronizer::BASELINE_SETTING)->value('value'));
    }

    #[Test]
    public function a_dry_run_does_not_record_the_baseline(): void
    {
        $this->setting(BackerSynchronizer::BASELINE_SETTING, '');
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $this->sync(['--dry-run' => true]);

        $this->assertEmpty($this->database()->table('settings')->where('key', BackerSynchronizer::BASELINE_SETTING)->value('value'));
    }

    #[Test]
    public function a_token_missing_a_scope_changes_nothing_and_says_why(): void
    {
        $this->known(5, BackerStatus::Recurring, [self::RECURRING_GROUP]);
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
        $this->assertSame([], $this->eventLog());
    }

    #[Test]
    public function hidden_emails_change_nothing_and_say_why(): void
    {
        $this->known(5, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', null)]);

        $output = $this->sync();

        $this->assertStringContainsString('cannot read backer emails', $output);
        $this->assertTrue($this->inGroup(5, self::RECURRING_GROUP));
    }

    #[Test]
    public function no_backers_at_all_needs_force_to_end_known_backings(): void
    {
        $this->known(5, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers();

        $this->assertStringContainsString('--force', $this->sync());
        $this->assertTrue($this->inGroup(5, self::RECURRING_GROUP));
    }

    #[Test]
    public function no_backers_at_all_with_force_ends_known_backings(): void
    {
        $this->known(5, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeOpenCollectiveProvider::backers();

        $this->sync(['--force' => true]);

        $this->assertFalse($this->inGroup(5, self::RECURRING_GROUP));
        $this->assertSame(['BackingEnded:5'], $this->eventLog());
    }

    #[Test]
    public function log_lines_carry_hours_and_minutes_once(): void
    {
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $output = $this->sync();

        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2} \d{2}, \d{4} @ \d{2}:\d{2} [AP]M \| Retrieving/m', $output);
        $this->assertDoesNotMatchRegularExpression('/@ \d{2}:\d{2} [AP]M \| [A-Z][a-z]{2} \d{2}, \d{4} @/', $output);
    }

    /** @return array<string, mixed>|null */
    protected function lastSync(): ?array
    {
        return json_decode($this->database()->table('settings')->where('key', SyncStatus::SETTING)->value('value') ?? 'null', true);
    }

    #[Test]
    public function a_successful_sync_records_its_outcome(): void
    {
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $this->sync();

        $last = $this->lastSync();
        $this->assertTrue($last['ok']);
        $this->assertSame(1, $last['backers']);
        $this->assertSame(1, $last['changes']);
        $this->assertNotEmpty($last['at']);
    }

    #[Test]
    public function a_failed_sync_records_why(): void
    {
        FakeOpenCollectiveProvider::reset([new Response(401, [], json_encode(['error' => ['message' => 'Invalid Personal Token (Api Key)']]))]);

        $this->sync();

        $last = $this->lastSync();
        $this->assertFalse($last['ok']);
        $this->assertSame('unauthorized', $last['kind']);
        $this->assertSame('Invalid Personal Token (Api Key)', $last['message']);
    }

    #[Test]
    public function a_refused_empty_result_is_recorded_as_a_failure(): void
    {
        $this->known(5, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        FakeOpenCollectiveProvider::backers();

        $this->sync();

        $this->assertSame('no_backers', $this->lastSync()['kind']);
    }

    #[Test]
    public function a_dry_run_does_not_record_an_outcome(): void
    {
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'alice@example.com')]);

        $this->sync(['--dry-run' => true]);

        $this->assertNull($this->lastSync());
    }
}
