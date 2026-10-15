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
 * The sync took over a group membership it did not create, such as one added by
 * hand, because the user's backing now justifies it. From now on the sync may
 * remove it when the backing ends and no other source still wants it.
 */
class BackerMembershipAdopted
{
    /**
     * @param array<int> $groupIds
     */
    public function __construct(
        public readonly User $user,
        public readonly array $groupIds
    ) {
    }
}
