<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Event;

use Flarum\User\User;

/**
 * Event fired when a user is removed from the backers group.
 */
class BackerRemoved
{
    /**
     * The Flarum user who was removed.
     *
     * @var User
     */
    public $user;

    /**
     * @param User $user The Flarum user who was removed from backers
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }
}
