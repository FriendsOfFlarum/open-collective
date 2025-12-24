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
    /**
     * @var SettingsRepositoryInterface
     */
    private $settings;

    /**
     * @var Dispatcher
     */
    private $events;

    private const MANAGED_USERS_KEY = 'fof-open-collective.users';

    public function __construct(SettingsRepositoryInterface $settings, Dispatcher $events)
    {
        $this->settings = $settings;
        $this->events = $events;
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
    private function findBackerDataForUser(User $user, array $backers): ?object
    {
        foreach ($backers as $backer) {
            $backerData = $backer->account ?? $backer;
            $email = $backerData->email ?? null;

            // Match by email
            if ($email && $user->email === $email) {
                return $backerData;
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

        // Filter out users who are still backers (matched by email)
        return $managedGroupUsers->filter(function ($user) use ($backerEmails) {
            // Check if user is matched by email
            if (in_array($user->email, $backerEmails)) {
                return false; // Keep this user (don't remove)
            }

            return true; // Remove this user
        });
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
     * @return Collection<int>
     */
    public function getManagedUsers(): Collection
    {
        return collect(json_decode($this->settings->get(self::MANAGED_USERS_KEY, '[]')));
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
}
