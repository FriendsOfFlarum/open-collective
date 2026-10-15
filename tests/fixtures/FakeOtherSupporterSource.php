<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Tests\fixtures;

use Flarum\Foundation\AbstractServiceProvider;
use FoF\OpenCollective\Services\BackerGroupClaims;

/**
 * Stands in for another supporter source (such as fof/github-sponsors) sharing the
 * same groups: it claims whichever users a test puts in {@see self::$wants}.
 */
class FakeOtherSupporterSource extends AbstractServiceProvider
{
    /** @var array<int, array<int>> User IDs wanted, by group ID */
    public static array $wants = [];

    public function register(): void
    {
        $this->container->bind(self::class.'.claims', fn () => fn (array $userIds, int $groupId) => array_values(array_intersect($userIds, self::$wants[$groupId] ?? [])));
        $this->container->tag([self::class.'.claims'], BackerGroupClaims::TAG);
    }
}
