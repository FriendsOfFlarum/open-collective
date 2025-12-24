<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Tests\Unit\Services;

use FoF\OpenCollective\Services\BackerMatcher;
use PHPUnit\Framework\TestCase;

class BackerMatcherTest extends TestCase
{
    /**
     * @var BackerMatcher
     */
    private $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new BackerMatcher();
    }

    public function testGetBackerEmails()
    {
        $backers = [
            (object) ['fromAccount' => (object) ['email' => 'test1@example.com']],
            (object) ['fromAccount' => (object) ['email' => 'test2@example.com']],
            (object) ['fromAccount' => (object) ['email' => null]], // No email
        ];

        $emails = $this->matcher->getBackerEmails($backers);

        $this->assertCount(2, $emails);
        $this->assertTrue($emails->contains('test1@example.com'));
        $this->assertTrue($emails->contains('test2@example.com'));
    }

    public function testGetBackerEmailsRemovesDuplicates()
    {
        $backers = [
            (object) ['fromAccount' => (object) ['email' => 'test@example.com']],
            (object) ['fromAccount' => (object) ['email' => 'test@example.com']],
        ];

        $emails = $this->matcher->getBackerEmails($backers);

        $this->assertCount(1, $emails);
    }

    public function testGetBackerEmailsHandlesMissingAccountProperty()
    {
        $backers = [
            (object) ['fromAccount' => (object) ['email' => 'test@example.com']],
            (object) [], // No fromAccount property
        ];

        $emails = $this->matcher->getBackerEmails($backers);

        $this->assertCount(1, $emails);
        $this->assertTrue($emails->contains('test@example.com'));
    }

    public function testCategorizeBackersByFrequencyAndStatus()
    {
        $backers = [
            (object) ['frequency' => 'MONTHLY', 'status' => 'ACTIVE', 'fromAccount' => (object) ['email' => 'active-monthly@example.com']],
            (object) ['frequency' => 'YEARLY', 'status' => 'ACTIVE', 'fromAccount' => (object) ['email' => 'active-yearly@example.com']],
            (object) ['frequency' => 'MONTHLY', 'status' => 'CANCELLED', 'fromAccount' => (object) ['email' => 'cancelled-monthly@example.com']],
            (object) ['frequency' => 'ONETIME', 'status' => 'PAID', 'fromAccount' => (object) ['email' => 'onetime@example.com']],
            (object) ['frequency' => null, 'status' => null, 'fromAccount' => (object) ['email' => 'null@example.com']],
        ];

        $categorized = $this->matcher->categorizeBackers($backers);

        // Only ACTIVE MONTHLY/YEARLY are recurring
        $this->assertCount(2, $categorized['recurring']);
        // CANCELLED recurring, ONETIME, and null are all one-time
        $this->assertCount(3, $categorized['onetime']);
    }

    public function testCategorizeBackersOnlyActiveRecurring()
    {
        $backers = [
            (object) ['frequency' => 'MONTHLY', 'status' => 'ACTIVE', 'fromAccount' => (object) ['email' => 'active@example.com']],
            (object) ['frequency' => 'MONTHLY', 'status' => 'CANCELLED', 'fromAccount' => (object) ['email' => 'cancelled@example.com']],
            (object) ['frequency' => 'MONTHLY', 'status' => 'PAUSED', 'fromAccount' => (object) ['email' => 'paused@example.com']],
        ];

        $categorized = $this->matcher->categorizeBackers($backers);

        // Only ACTIVE recurring
        $this->assertCount(1, $categorized['recurring']);
        $this->assertEquals('active@example.com', $categorized['recurring'][0]->fromAccount->email);

        // CANCELLED and PAUSED are treated as one-time
        $this->assertCount(2, $categorized['onetime']);
    }
}
