<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Providers;

use Flarum\Foundation\AbstractServiceProvider;
use FoF\OpenCollective\Services\BackerGroupClaims;

class SupporterGroupsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->tag([BackerGroupClaims::class], BackerGroupClaims::TAG);
    }
}
