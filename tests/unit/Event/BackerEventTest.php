<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Tests\Unit\Event;

use Flarum\User\User;
use FoF\OpenCollective\Event\BackerAdded;
use FoF\OpenCollective\Event\BackerRemoved;
use PHPUnit\Framework\TestCase;

class BackerEventTest extends TestCase
{
    public function testBackerAddedEvent()
    {
        $user = new User();
        $user->id = 1;
        $user->username = 'testuser';

        $backerData = (object) [
            'email' => 'test@example.com',
        ];

        $event = new BackerAdded($user, $backerData);

        $this->assertSame($user, $event->user);
        $this->assertSame($backerData, $event->backerData);
        $this->assertEquals('test@example.com', $event->backerData->email);
    }

    public function testBackerAddedEventWithoutData()
    {
        $user = new User();
        $user->id = 1;

        $event = new BackerAdded($user);

        $this->assertSame($user, $event->user);
        $this->assertNull($event->backerData);
    }

    public function testBackerRemovedEvent()
    {
        $user = new User();
        $user->id = 1;
        $user->username = 'testuser';

        $event = new BackerRemoved($user);

        $this->assertSame($user, $event->user);
    }
}
