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
use Illuminate\Support\Collection;

class BackerMatcher
{
    /**
     * Match Open Collective backers to Flarum users.
     *
     * Uses email matching between backer emails and Flarum user emails.
     *
     * @param array $backers Array of backer data from Open Collective API
     *
     * @return Collection<User>
     */
    public function matchBackersToUsers(array $backers): Collection
    {
        $backerEmails = $this->getBackerEmails($backers);

        return User::query()
            ->where('is_email_confirmed', true)
            ->whereIn('email', $backerEmails)
            ->get();
    }

    /**
     * Get all backer emails from the backer data.
     *
     * @param array $backers Array of backer data from Open Collective API
     *
     * @return Collection<string>
     */
    public function getBackerEmails(array $backers): Collection
    {
        return collect($backers)
            ->map(function ($member) {
                return $member->account->email ?? null;
            })
            ->filter()
            ->unique();
    }
}
