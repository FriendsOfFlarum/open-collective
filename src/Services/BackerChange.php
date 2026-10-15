<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Services;

use Flarum\User\User;
use FoF\OpenCollective\BackerStatus;

/**
 * What one sync changed (or, in a dry run, would change) for one user.
 */
class BackerChange
{
    /**
     * @param array<int> $addedGroupIds
     * @param array<int> $removedGroupIds
     */
    public function __construct(
        public readonly User $user,
        public readonly ?BackerStatus $previous,
        public readonly ?BackerStatus $current,
        public readonly array $addedGroupIds,
        public readonly array $removedGroupIds
    ) {
    }

    public function statusChanged(): bool
    {
        return $this->previous !== $this->current;
    }
}
