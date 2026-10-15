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

        if ($backerEmails->isEmpty()) {
            return new Collection();
        }

        // Emails are compared lowercased in PHP; query both spellings so the
        // indexed lookup also matches on case-sensitive databases (PostgreSQL).
        $rawEmails = collect($backers)->map(fn ($order) => $order->fromAccount->email ?? null)->filter();

        return User::query()
            ->where('is_email_confirmed', true)
            ->whereIn('email', $backerEmails->merge($rawEmails)->unique()->values())
            ->get()
            ->filter(fn (User $user) => $backerEmails->contains(strtolower($user->email)))
            ->values();
    }

    /**
     * Get all backer emails from the backer data.
     *
     * @param array $backers Array of backer data from Open Collective API (Order objects)
     *
     * @return Collection<int, lowercase-string&non-falsy-string> Unique
     */
    public function getBackerEmails(array $backers): Collection
    {
        return collect($backers)
            ->map(fn ($order) => isset($order->fromAccount->email) ? strtolower($order->fromAccount->email) : null)
            ->filter()
            ->unique()
            ->values();
    }
}
