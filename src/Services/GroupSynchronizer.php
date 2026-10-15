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

use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use FoF\OpenCollective\Event\BackerAdded;
use FoF\OpenCollective\Event\BackerRemoved;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;

class GroupSynchronizer
{
    private const MANAGED_USERS_KEY = 'fof-open-collective.users';
    private const MANAGED_ONETIME_USERS_KEY = 'fof-open-collective.onetime_users';

    public function __construct(private SettingsRepositoryInterface $settings, private Dispatcher $events)
    {
    }

    /**
     * Synchronize both recurring and one-time backer groups.
     *
     * @param Group|null       $recurringGroup   The recurring backers group
     * @param Group|null       $onetimeGroup     The one-time backers group (optional)
     * @param Collection<User> $recurringUsers   Users who are recurring backers
     * @param Collection<User> $onetimeUsers     Users who are one-time backers
     * @param array<string>    $recurringEmails  Recurring backer emails
     * @param array<string>    $onetimeEmails    One-time backer emails
     * @param bool             $dryRun           If true, no changes will be made
     * @param array            $recurringBackers Raw recurring backer data
     * @param array            $onetimeBackers   Raw one-time backer data
     *
     * @return array{recurring: array, onetime: array}
     */
    public function synchronizeBothGroups(
        ?Group $recurringGroup,
        ?Group $onetimeGroup,
        Collection $recurringUsers,
        Collection $onetimeUsers,
        array $recurringEmails,
        array $onetimeEmails,
        bool $dryRun = false,
        array $recurringBackers = [],
        array $onetimeBackers = []
    ): array {
        $recurringResult = ['added' => collect(), 'removed' => collect(), 'moved_to_onetime' => collect()];
        $onetimeResult = ['added' => collect(), 'removed' => collect()];

        // If both groups are the same, treat all backers as a single group
        if ($recurringGroup && $onetimeGroup && $recurringGroup->id === $onetimeGroup->id) {
            $allUsers = $recurringUsers->merge($onetimeUsers)->unique('id');
            $allEmails = array_unique(array_merge($recurringEmails, $onetimeEmails));
            $allBackers = array_merge($recurringBackers, $onetimeBackers);

            $result = $this->synchronize($recurringGroup, $allUsers, $allEmails, $dryRun, $allBackers);

            return [
                'recurring' => [
                    'added'            => $result['added'],
                    'removed'          => $result['removed'],
                    'moved_to_onetime' => collect(),
                ],
                'onetime' => [
                    'added'   => collect(),
                    'removed' => collect(),
                ],
            ];
        }

        // Handle recurring group
        if ($recurringGroup) {
            $usersManaging = $this->getManagedUsers();

            // Find users who should be moved from recurring to one-time
            // (users in recurring group who are now in one-time backers list)
            $usersToMoveToOnetime = collect();
            if ($onetimeGroup) {
                $onetimeEmailsCollection = collect($onetimeEmails)->map(fn ($email) => strtolower($email));
                $recurringEmailsLower = array_map('strtolower', $recurringEmails);
                $usersToMoveToOnetime = $recurringGroup->users()
                    ->whereIn('users.id', $usersManaging)
                    ->get()
                    ->filter(function ($user) use ($onetimeEmailsCollection, $recurringEmailsLower) {
                        $email = strtolower($user->email);

                        return $onetimeEmailsCollection->contains($email) && !in_array($email, $recurringEmailsLower, true);
                    });
            }

            // Remove from recurring group (includes users who stopped backing AND users moving to one-time)
            $usersToRemove = $this->getUsersToRemove($recurringGroup, $usersManaging, $recurringEmails);

            if (!$dryRun && $usersToRemove->count() > 0) {
                $this->removeUsersFromGroup($recurringGroup, $usersToRemove);

                foreach ($usersToRemove as $user) {
                    // Don't dispatch removed event if moving to one-time group
                    if (!$usersToMoveToOnetime->contains('id', $user->id)) {
                        $this->events->dispatch(new BackerRemoved($user));
                    }
                }
            }

            $recurringResult['removed'] = $usersToRemove->diff($usersToMoveToOnetime);
            $recurringResult['moved_to_onetime'] = $usersToMoveToOnetime;

            // Update managed users after removals
            foreach ($usersToRemove as $user) {
                $usersManaging = $usersManaging->reject(function ($id) use ($user) {
                    return $id == $user->id;
                });
            }

            // Add to recurring group
            $usersToAdd = $this->addUsersToGroup($recurringGroup, $recurringUsers, $usersManaging, $dryRun);

            if (!$dryRun) {
                foreach ($usersToAdd as $user) {
                    $backerData = $this->findBackerDataForUser($user, $recurringBackers);
                    $this->events->dispatch(new BackerAdded($user, $backerData));
                }

                // Saved after removal-only runs too: a removed user left on the list
                // would be stripped again if an admin later re-added them by hand.
                $usersManaging = $usersManaging->merge($usersToAdd->pluck('id'));
                $this->updateManagedUsers($usersManaging);
            }

            $recurringResult['added'] = $usersToAdd;
        }

        // Handle one-time group (if configured)
        if ($onetimeGroup) {
            $onetimeUsersManaging = $this->getManagedOnetimeUsers();

            // Add users who moved from recurring
            $usersToAdd = $onetimeUsers->merge($recurringResult['moved_to_onetime']);

            // Remove users from one-time group who are no longer backers
            $usersToRemove = $this->getUsersToRemove($onetimeGroup, $onetimeUsersManaging, $onetimeEmails);

            if (!$dryRun && $usersToRemove->count() > 0) {
                $this->removeUsersFromGroup($onetimeGroup, $usersToRemove);

                $recurringEmailsLower = array_map('strtolower', $recurringEmails);

                foreach ($usersToRemove as $user) {
                    // Upgrading to a recurring backer is not the end of their backing.
                    if (!in_array(strtolower($user->email), $recurringEmailsLower, true)) {
                        $this->events->dispatch(new BackerRemoved($user));
                    }
                }
            }

            // Update managed users after removals
            foreach ($usersToRemove as $user) {
                $onetimeUsersManaging = $onetimeUsersManaging->reject(function ($id) use ($user) {
                    return $id == $user->id;
                });
            }

            // Add to one-time group
            $usersAdded = $this->addUsersToGroup($onetimeGroup, $usersToAdd, $onetimeUsersManaging, $dryRun);

            if (!$dryRun) {
                foreach ($usersAdded as $user) {
                    $backerData = $this->findBackerDataForUser($user, $onetimeBackers);
                    $this->events->dispatch(new BackerAdded($user, $backerData));
                }

                $onetimeUsersManaging = $onetimeUsersManaging->merge($usersAdded->pluck('id'));
                $this->updateManagedOnetimeUsers($onetimeUsersManaging);
            }

            $onetimeResult['added'] = $usersAdded;
            $onetimeResult['removed'] = $usersToRemove;
        }

        return [
            'recurring' => $recurringResult,
            'onetime'   => $onetimeResult,
        ];
    }

    /**
     * Synchronize group memberships for backers.
     *
     * @param Group            $group        The group to synchronize
     * @param Collection<User> $backerUsers  Users who are backers
     * @param array<string>    $backerEmails Backer email addresses
     * @param bool             $dryRun       If true, no changes will be made
     * @param array            $backers      Raw backer data from Open Collective API
     *
     * @return array{added: Collection<User>, removed: Collection<User>}
     */
    public function synchronize(
        Group $group,
        Collection $backerUsers,
        array $backerEmails,
        bool $dryRun = false,
        array $backers = []
    ): array {
        $usersManaging = $this->getManagedUsers();

        // Remove group from users who are no longer backers
        $usersToRemove = $this->getUsersToRemove($group, $usersManaging, $backerEmails);

        if (!$dryRun) {
            $this->removeUsersFromGroup($group, $usersToRemove);

            // Dispatch BackerRemoved events
            foreach ($usersToRemove as $user) {
                $this->events->dispatch(new BackerRemoved($user));
            }
        }

        // Update managed users list after removals
        foreach ($usersToRemove as $user) {
            $usersManaging = $usersManaging->reject(function ($id) use ($user) {
                return $id == $user->id;
            });
        }

        // Add group to users who should have it
        $usersToAdd = $this->addUsersToGroup($group, $backerUsers, $usersManaging, $dryRun);

        if (!$dryRun) {
            // Dispatch BackerAdded events with Open Collective data
            foreach ($usersToAdd as $user) {
                $backerData = $this->findBackerDataForUser($user, $backers);
                $this->events->dispatch(new BackerAdded($user, $backerData));
            }

            // Update managed users list after additions
            $usersManaging = $usersManaging->merge($usersToAdd->pluck('id'));
            $this->updateManagedUsers($usersManaging);
        }

        return [
            'added'   => $usersToAdd,
            'removed' => $usersToRemove,
        ];
    }

    /**
     * Find the Open Collective backer data for a given Flarum user.
     *
     * @param User  $user
     * @param array $backers
     *
     * @return object|null
     */
    protected function findBackerDataForUser(User $user, array $backers): ?object
    {
        foreach ($backers as $backer) {
            $email = $backer->fromAccount->email ?? null;

            if ($email && strtolower($user->email) === strtolower($email)) {
                return $backer->fromAccount;
            }
        }

        return null;
    }

    /**
     * Get users who should be removed from the group.
     *
     * @param Group         $group
     * @param Collection    $usersManaging
     * @param array<string> $backerEmails
     *
     * @return Collection<User>
     */
    private function getUsersToRemove(
        Group $group,
        Collection $usersManaging,
        array $backerEmails
    ): Collection {
        // Get all users in the group that are managed by this extension
        $managedGroupUsers = $group->users()
            ->whereIn('users.id', $usersManaging)
            ->get();

        $backerEmails = array_map('strtolower', $backerEmails);

        return $managedGroupUsers->reject(fn ($user) => in_array(strtolower($user->email), $backerEmails, true));
    }

    /**
     * Remove users from the group.
     *
     * @param Group            $group
     * @param Collection<User> $users
     */
    private function removeUsersFromGroup(Group $group, Collection $users): void
    {
        $group->users()->detach($users->pluck('id'));
    }

    /**
     * Add users to the group if they don't already have it.
     *
     * @param Group            $group
     * @param Collection<User> $backerUsers
     * @param Collection       $usersManaging
     * @param bool             $dryRun        If true, no changes will be made
     *
     * @return Collection<User> Users that were added
     */
    private function addUsersToGroup(Group $group, Collection $backerUsers, Collection $usersManaging, bool $dryRun = false): Collection
    {
        $usersAdded = collect();

        $backerUsers->each(function ($user) use ($group, &$usersAdded, $dryRun) {
            if (!$user->groups()->find($group->id)) {
                if (!$dryRun) {
                    $user->groups()->attach($group->id);
                }
                $usersAdded->push($user);
            }
        });

        return $usersAdded;
    }

    /**
     * Get the list of users currently managed by this extension.
     *
     * @return Collection<int, int>
     */
    public function getManagedUsers(): Collection
    {
        return collect((array) json_decode($this->settings->get(self::MANAGED_USERS_KEY, '[]')));
    }

    /**
     * Update the list of users managed by this extension.
     *
     * @param Collection<int> $users
     */
    private function updateManagedUsers(Collection $users): void
    {
        $this->settings->set(self::MANAGED_USERS_KEY, $users->values()->unique()->toJson());
    }

    /**
     * Get the list of one-time users currently managed by this extension.
     *
     * @return Collection<int, int>
     */
    public function getManagedOnetimeUsers(): Collection
    {
        return collect((array) json_decode($this->settings->get(self::MANAGED_ONETIME_USERS_KEY, '[]')));
    }

    /**
     * Update the list of one-time users managed by this extension.
     *
     * @param Collection<int> $users
     */
    private function updateManagedOnetimeUsers(Collection $users): void
    {
        $this->settings->set(self::MANAGED_ONETIME_USERS_KEY, $users->values()->unique()->toJson());
    }
}
