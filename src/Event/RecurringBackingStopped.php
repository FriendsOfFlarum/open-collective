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
 * A recurring backer's subscription ended (cancelled or paused); they remain a past, one-time backer.
 */
class RecurringBackingStopped extends AbstractBackerStatusChanged
{
}
