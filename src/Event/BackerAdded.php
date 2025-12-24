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
 * Event fired when a user is added to the backers group.
 */
class BackerAdded
{
    /**
     * The Flarum user who was added.
     *
     * @var User
     */
    public $user;

    /**
     * The Open Collective backer data from the API.
     *
     * @var object|null
     */
    public $backerData;

    /**
     * @param User        $user       The Flarum user who was added as a backer
     * @param object|null $backerData The Open Collective backer data (contains email, etc.)
     */
    public function __construct(User $user, ?object $backerData = null)
    {
        $this->user = $user;
        $this->backerData = $backerData;
    }
}
