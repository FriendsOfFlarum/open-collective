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
 * A user became a recurring backer, either new or upgrading from a one-time backing.
 */
class RecurringBackingStarted extends AbstractBackerStatusChanged
{
}
