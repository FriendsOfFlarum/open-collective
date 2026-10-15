<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Api;

use Flarum\Api\Schema;
use FoF\OpenCollective\Services\SupporterCounts;

/**
 * Public counts of backers a supporters page cannot list by name. Aggregates
 * only: nothing about an incognito backer is ever stored.
 */
class ForumAttributes
{
    public function __construct(protected SupporterCounts $counts)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Integer::make('openCollectivePrivateRecurring')->get(fn () => $this->counts->get()['private']['recurring']),
            Schema\Integer::make('openCollectivePrivateOnetime')->get(fn () => $this->counts->get()['private']['onetime']),
            Schema\Integer::make('openCollectiveUnmatchedRecurring')->get(fn () => $this->counts->get()['unmatched']['recurring']),
            Schema\Integer::make('openCollectiveUnmatchedOnetime')->get(fn () => $this->counts->get()['unmatched']['onetime']),
        ];
    }
}
