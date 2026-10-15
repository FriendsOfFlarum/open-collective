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

/**
 * A user is no longer a backer at all, for example after a refund or once their account no longer matches.
 */
class BackingEnded extends AbstractBackerStatusChanged
{
}
