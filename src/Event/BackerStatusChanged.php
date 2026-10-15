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
 * Implemented by every backer status event. Listen to this interface to react to
 * all of them; the concrete event is an {@see AbstractBackerStatusChanged}.
 */
interface BackerStatusChanged
{
}
