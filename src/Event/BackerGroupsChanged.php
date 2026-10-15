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
 * The sync changed a user's groups without their backing changing: a backer
 * re-added to a group they were missing from, or the first sync after install.
 */
class BackerGroupsChanged
{
    /**
     * @param array<int> $oldGroupIds
     * @param array<int> $newGroupIds
     */
    public function __construct(
        public readonly User $user,
        public readonly array $oldGroupIds,
        public readonly array $newGroupIds
    ) {
    }
}
