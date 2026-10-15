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
use FoF\OpenCollective\BackerStatus;

/**
 * A user's backing changed. Group IDs are the user's full group list before and
 * after this sync; they are equal when the extension changed no membership.
 */
abstract class AbstractBackerStatusChanged implements BackerStatusChanged
{
    /**
     * @param object|null $account     The Open Collective account (id, email); null when backing ended
     * @param array<int>  $oldGroupIds
     * @param array<int>  $newGroupIds
     */
    public function __construct(
        public readonly User $user,
        public readonly ?BackerStatus $previous,
        public readonly ?BackerStatus $current,
        public readonly ?object $account,
        public readonly array $oldGroupIds,
        public readonly array $newGroupIds
    ) {
    }
}
