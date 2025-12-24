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
     * Categorize backers by type (recurring vs one-time).
     *
     * @param array $backers Array of backer data from Open Collective API (Order objects)
     *
     * @return array{recurring: array, onetime: array}
     */
    public function categorizeBackers(array $backers): array
    {
        $recurring = [];
        $onetime = [];

        foreach ($backers as $backer) {
            // The frequency is directly on the order object from the orders query
            $frequency = $backer->frequency ?? null;
            $status = $backer->status ?? null;

            // Only ACTIVE MONTHLY/YEARLY subscriptions count as recurring
            // CANCELLED/PAUSED recurring subscriptions are treated as one-time backers
            if (($frequency === 'MONTHLY' || $frequency === 'YEARLY') && $status === 'ACTIVE') {
                $recurring[] = $backer;
            } else {
                $onetime[] = $backer;
            }
        }

        return [
            'recurring' => $recurring,
            'onetime'   => $onetime,
        ];
    }

    /**
     * Match backers of a specific type to Flarum users.
     *
     * @param array $backers Array of backer data from Open Collective API
     *
     * @return Collection<User>
     */
    public function matchBackersOfTypeToUsers(array $backers): Collection
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
     * @param array $backers Array of backer data from Open Collective API (Order objects)
     *
     * @return Collection<string>
     */
    public function getBackerEmails(array $backers): Collection
    {
        return collect($backers)
            ->map(function ($order) {
                // Orders use fromAccount instead of account
                return $order->fromAccount->email ?? null;
            })
            ->filter()
            ->unique();
    }
}
