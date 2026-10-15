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

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use FoF\OpenCollective\Backer;
use FoF\OpenCollective\BackerStatus;
use FoF\OpenCollective\Event\BackerGroupsChanged;
use FoF\OpenCollective\Event\BackerMembershipAdopted;
use FoF\OpenCollective\Event\BackingEnded;
use FoF\OpenCollective\Event\OneTimeBackingStarted;
use FoF\OpenCollective\Event\RecurringBackingStarted;
use FoF\OpenCollective\Event\RecurringBackingStopped;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;

/**
 * Brings each matched user's backer status and group memberships in line with
 * Open Collective.
 *
 * Ownership: the sync owns a membership it added, or one it adopted because the
 * user's backing justifies it (for example one added by hand). It only removes
 * memberships it owns, and only when no other supporter source (see
 * {@see BackerGroupClaims}) still wants them. A supporter is never in both the
 * monthly and the one-time group: the monthly group wins.
 */
class BackerSynchronizer
{
    /**
     * Set after the first applied sync. Until then statuses are recorded without
     * firing status events, so installing or upgrading does not announce every
     * existing backer as new.
     */
    public const BASELINE_SETTING = 'fof-open-collective.baseline_recorded_at';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Dispatcher $events,
        protected BackerMatcher $matcher,
        protected Container $container
    ) {
    }

    public function isBaseline(): bool
    {
        return !$this->settings->get(self::BASELINE_SETTING);
    }

    /**
     * @param array<object> $backers One order per person, as returned by {@see \FoF\OpenCollective\Api\OpenCollectiveClient::fetchBackers()}
     *
     * @return Collection<int, BackerChange> One entry per user whose status or groups changed, or whose membership was adopted
     */
    public function synchronize(array $backers, Group $recurringGroup, ?Group $onetimeGroup, bool $dryRun = false): Collection
    {
        $baseline = $this->isBaseline();
        $desired = $this->desiredStatuses($backers);
        $known = Backer::query()->get()->keyBy('user_id');
        $userIds = array_values(array_unique(array_merge(array_keys($desired), $known->keys()->map(fn ($id) => (int) $id)->all())));

        $users = User::query()->whereIn('id', $userIds)->with('groups')->get();

        $recurringId = (int) $recurringGroup->id;
        $onetimeId = $onetimeGroup ? (int) $onetimeGroup->id : null;
        $othersWant = [$recurringId => $this->othersWanting($userIds, $recurringId)];

        if ($onetimeId && $onetimeId !== $recurringId) {
            $othersWant[$onetimeId] = $this->othersWanting($userIds, $onetimeId);
        }

        $changes = new Collection();

        foreach ($users as $user) {
            /** @var Backer $backer */
            $backer = $known->get($user->id) ?? new Backer(['user_id' => $user->id]);
            [$current, $account] = $desired[$user->id] ?? [null, null];
            $change = $this->reconcile($user, $backer, $current, $account, $recurringId, $onetimeId, $othersWant, $dryRun, $baseline);

            if ($change) {
                $changes->push($change);
            }
        }

        if ($baseline && !$dryRun) {
            $this->settings->set(self::BASELINE_SETTING, Carbon::now()->toIso8601String());
        }

        return $changes;
    }

    /**
     * @param array<int, array<int>> $othersWant User IDs other sources want in each group, by group ID
     */
    protected function reconcile(
        User $user,
        Backer $backer,
        ?BackerStatus $current,
        ?object $account,
        int $recurringId,
        ?int $onetimeId,
        array $othersWant,
        bool $dryRun,
        bool $baseline
    ): ?BackerChange {
        $previous = $backer->status;
        $oldGroupIds = $user->groups->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $groups = array_fill_keys($oldGroupIds, true);
        $owned = array_fill_keys($backer->managedGroupIds(), true);
        $add = $remove = $adopt = [];
        $uid = (int) $user->id;

        $othersWantRecurring = in_array($uid, $othersWant[$recurringId] ?? [], true);
        $singleGroup = !$onetimeId || $onetimeId === $recurringId;
        $wantRecurring = $current === BackerStatus::Recurring || ($onetimeId === $recurringId && $current !== null);

        // The monthly group.
        if ($wantRecurring) {
            if (!isset($groups[$recurringId])) {
                $add[] = $recurringId;
            } elseif (!isset($owned[$recurringId])) {
                $adopt[] = $recurringId;
            }
        } elseif (isset($groups[$recurringId], $owned[$recurringId]) && !$othersWantRecurring) {
            $remove[] = $recurringId;
        }

        // The one-time group, which a monthly supporter is never also in.
        if (!$singleGroup) {
            $inRecurringAfter = (isset($groups[$recurringId]) || in_array($recurringId, $add, true)) && !in_array($recurringId, $remove, true);
            $wantOnetime = $current === BackerStatus::OneTime;
            $othersWantOnetime = in_array($uid, $othersWant[$onetimeId] ?? [], true);

            if ($inRecurringAfter) {
                if (isset($groups[$onetimeId])) {
                    $remove[] = $onetimeId;
                }
            } elseif ($othersWantRecurring) {
                // Another source is about to add the monthly group; it removes this one then.
            } elseif ($wantOnetime) {
                if (!isset($groups[$onetimeId])) {
                    $add[] = $onetimeId;
                } elseif (!isset($owned[$onetimeId])) {
                    $adopt[] = $onetimeId;
                }
            } elseif (isset($groups[$onetimeId], $owned[$onetimeId]) && !$othersWantOnetime) {
                $remove[] = $onetimeId;
            }
        }

        $newGroupIds = array_values(array_diff(array_unique(array_merge($oldGroupIds, $add)), $remove));
        sort($newGroupIds);

        // Ownership of a membership that no longer exists, or that this source no
        // longer wants (another source still does, and adopts it), is dropped.
        $wanted = array_filter([$wantRecurring ? $recurringId : null, (!$singleGroup && $current === BackerStatus::OneTime) ? $onetimeId : null]);
        $newOwned = array_values(array_intersect(array_unique(array_merge(array_keys($owned), $add, $adopt)), $newGroupIds, $wanted));
        sort($newOwned);
        $oldOwned = array_keys($owned);
        sort($oldOwned);

        $statusChanged = $previous !== $current;

        if (!$statusChanged && !$add && !$remove && !$adopt && $newOwned === $oldOwned) {
            return null;
        }

        if (!$dryRun) {
            $add && $user->groups()->attach($add);
            $remove && $user->groups()->detach($remove);

            if ($current === null && !$newOwned) {
                $backer->exists && $backer->delete();
            } else {
                $backer->status = $current;
                $backer->managed_group_ids = $newOwned;

                if ($statusChanged) {
                    $backer->status_changed_at = Carbon::now();
                }

                $backer->save();
            }

            if ($statusChanged && !$baseline) {
                $event = match (true) {
                    $current === BackerStatus::Recurring                                          => RecurringBackingStarted::class,
                    $previous === BackerStatus::Recurring && $current === BackerStatus::OneTime   => RecurringBackingStopped::class,
                    $current === BackerStatus::OneTime                                            => OneTimeBackingStarted::class,
                    default                                                                       => BackingEnded::class,
                };

                $this->events->dispatch(new $event($user, $previous, $current, $account, $oldGroupIds, $newGroupIds));
            } elseif ($add || $remove) {
                $this->events->dispatch(new BackerGroupsChanged($user, $oldGroupIds, $newGroupIds));
            }

            if ($adopt) {
                $this->events->dispatch(new BackerMembershipAdopted($user, $adopt));
            }
        }

        // Dropping ownership alone is bookkeeping, not a change to the user.
        return ($statusChanged || $add || $remove || $adopt) ? new BackerChange($user, $previous, $current, $add, $remove, $adopt) : null;
    }

    /**
     * User IDs that other supporter sources want in the group.
     *
     * @param array<int> $userIds
     *
     * @return array<int>
     */
    protected function othersWanting(array $userIds, int $groupId): array
    {
        $wanted = [];

        foreach ($this->container->tagged(BackerGroupClaims::TAG) as $claims) {
            if ($claims instanceof BackerGroupClaims || !is_callable($claims)) {
                continue;
            }

            array_push($wanted, ...array_map('intval', (array) $claims($userIds, $groupId)));
        }

        return array_values(array_unique($wanted));
    }

    /**
     * Each matched user's status per Open Collective, with their account data.
     * A recurring backing wins over a one-time one.
     *
     * @param array<object> $backers
     *
     * @return array<int, array{0: BackerStatus, 1: object|null}>
     */
    protected function desiredStatuses(array $backers): array
    {
        $accounts = [];

        foreach ($backers as $backer) {
            if ($email = $backer->fromAccount->email ?? null) {
                $accounts[strtolower($email)] ??= $backer->fromAccount;
            }
        }

        $categorized = $this->matcher->categorizeBackers($backers);
        $desired = [];

        foreach ([[BackerStatus::OneTime, $categorized['onetime']], [BackerStatus::Recurring, $categorized['recurring']]] as [$status, $orders]) {
            foreach ($this->matcher->matchBackersOfTypeToUsers($orders) as $user) {
                $desired[$user->id] = [$status, $accounts[strtolower($user->email)] ?? null];
            }
        }

        return $desired;
    }
}
