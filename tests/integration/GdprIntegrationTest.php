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
use Flarum\Audit\AuditLogger;
use Flarum\Gdpr\DataProcessor;
use Flarum\Gdpr\Models\ErasureRequest;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use FoF\OpenCollective\Backer;
use FoF\OpenCollective\BackerStatus;
use FoF\OpenCollective\Data\OpenCollectiveBacker;
use FoF\OpenCollective\Services\BackerSynchronizer;
use FoF\OpenCollective\Tests\fixtures\FakeOpenCollectiveProvider;
use Illuminate\Contracts\Filesystem\Factory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * With flarum/gdpr enabled, a member's backer record is exported with their data
 * and erased with their account.
 */
class GdprIntegrationTest extends SyncTestCase
{
    protected function extensions(): array
    {
        return ['flarum-audit', 'flarum-gdpr', 'fof-open-collective'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        AuditLogger::$testMode = true;

        $this->setting(BackerSynchronizer::BASELINE_SETTING, '2026-01-01T00:00:00+00:00');
        $this->setting('mail_driver', 'log');
        $this->setting('flarum-gdpr.allow-anonymization', true);
        $this->setting('flarum-gdpr.allow-deletion', true);

        $this->known(5, BackerStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[5, self::RECURRING_GROUP]]);

        $this->prepareDatabase([
            'gdpr_erasure' => [
                ['id' => 1, 'user_id' => 5, 'verification_token' => 'tok', 'status' => ErasureRequest::STATUS_USER_CONFIRMED, 'created_at' => Carbon::now(), 'user_confirmed_at' => Carbon::now()],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        AuditLogger::$testMode = false;

        parent::tearDown();
    }

    protected function erase(string $mode): void
    {
        $response = $this->send($this->request('PATCH', '/api/user-erasure-requests/1', [
            'authenticatedAs' => 1,
            'json'            => ['data' => ['attributes' => ['processorComment' => 'Test', 'processedMode' => $mode]]],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    protected function dataType(int $userId): OpenCollectiveBacker
    {
        $container = $this->app()->getContainer();

        return new OpenCollectiveBacker(
            User::findOrFail($userId),
            null,
            $container->make(Factory::class),
            $container->make(SettingsRepositoryInterface::class),
            $container->make(UrlGenerator::class),
            $container->make(TranslatorInterface::class)
        );
    }

    #[Test]
    public function the_backer_record_is_registered_with_gdpr(): void
    {
        $this->assertArrayHasKey(OpenCollectiveBacker::class, $this->app()->getContainer()->make(DataProcessor::class)->types());
    }

    #[Test]
    public function the_export_includes_the_backer_record(): void
    {
        $export = $this->dataType(5)->export();

        $this->assertSame(['open-collective/backer.json'], array_keys($export));
        $data = json_decode($export['open-collective/backer.json'], true);
        $this->assertSame('recurring', $data['status']);
        $this->assertSame(['Supporter'], $data['managed_groups']);
        $this->assertNotEmpty($data['status_changed_at']);
    }

    #[Test]
    public function a_member_who_was_never_a_backer_has_nothing_to_export(): void
    {
        $this->assertNull($this->dataType(4)->export());
    }

    #[Test]
    public function anonymizing_a_backer_erases_their_record_and_the_sync_stays_silent(): void
    {
        $this->erase(ErasureRequest::MODE_ANONYMIZATION);

        $this->assertNull(Backer::find(5));

        // Open Collective still lists dave; the anonymized account no longer matches.
        FakeOpenCollectiveProvider::backers(monthly: [$this->order('ACTIVE', 'MONTHLY', 'dave@example.com')]);
        $this->sync();

        $this->assertSame([], $this->eventLog());
        $this->assertNull(Backer::find(5));
        $this->assertFalse($this->database()->table('audit_log')->where('action', 'like', 'fof_open_collective.%')->exists());
    }

    #[Test]
    public function deleting_a_backer_erases_their_record(): void
    {
        $this->erase(ErasureRequest::MODE_DELETION);

        $this->assertNull(User::find(5));
        $this->assertNull(Backer::find(5));
    }
}
