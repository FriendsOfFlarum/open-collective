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
            (object) ['account' => (object) ['email' => 'test1@example.com']],
            (object) ['account' => (object) ['email' => 'test2@example.com']],
            (object) ['account' => (object) ['email' => null]], // No email
        ];

        $emails = $this->matcher->getBackerEmails($backers);

        $this->assertCount(2, $emails);
        $this->assertTrue($emails->contains('test1@example.com'));
        $this->assertTrue($emails->contains('test2@example.com'));
    }

    public function testGetBackerEmailsRemovesDuplicates()
    {
        $backers = [
            (object) ['account' => (object) ['email' => 'test@example.com']],
            (object) ['account' => (object) ['email' => 'test@example.com']],
        ];

        $emails = $this->matcher->getBackerEmails($backers);

        $this->assertCount(1, $emails);
    }

    public function testGetBackerEmailsHandlesMissingAccountProperty()
    {
        $backers = [
            (object) ['account' => (object) ['email' => 'test@example.com']],
            (object) [], // No account property
        ];

        $emails = $this->matcher->getBackerEmails($backers);

        $this->assertCount(1, $emails);
        $this->assertTrue($emails->contains('test@example.com'));
    }
}
