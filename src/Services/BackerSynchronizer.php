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
use FoF\OpenCollective\Event\BackingEnded;
use FoF\OpenCollective\Event\OneTimeBackingStarted;
use FoF\OpenCollective\Event\RecurringBackingStarted;
use FoF\OpenCollective\Event\RecurringBackingStopped;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;

/**
 * Brings each matched user's backer status and group memberships in line with
 * Open Collective. Memberships are only removed where this extension added them,
 * so members an admin added by hand are never taken out of a group.
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
        protected BackerMatcher $matcher
    ) {
    }

    public function isBaseline(): bool
    {
        return !$this->settings->get(self::BASELINE_SETTING);
    }

    /**
     * @param array<object> $backers One order per person, as returned by {@see \FoF\OpenCollective\Api\OpenCollectiveClient::fetchBackers()}
     *
     * @return Collection<int, BackerChange> One entry per user whose status or groups changed
     */
    public function synchronize(array $backers, Group $recurringGroup, ?Group $onetimeGroup, bool $dryRun = false): Collection
    {
        $baseline = $this->isBaseline();
        $desired = $this->desiredStatuses($backers);
        $known = Backer::query()->get()->keyBy('user_id');

        $users = User::query()
            ->whereIn('id', array_unique(array_merge(array_keys($desired), $known->keys()->all())))
            ->with('groups')
            ->get();

        $changes = new Collection();

        foreach ($users as $user) {
            /** @var Backer $backer */
            $backer = $known->get($user->id) ?? new Backer(['user_id' => $user->id]);
            [$current, $account] = $desired[$user->id] ?? [null, null];
            $change = $this->reconcile($user, $backer, $current, $account, $recurringGroup, $onetimeGroup, $dryRun, $baseline);

            if ($change) {
                $changes->push($change);
            }
        }

        if ($baseline && !$dryRun) {
            $this->settings->set(self::BASELINE_SETTING, Carbon::now()->toIso8601String());
        }

        return $changes;
    }

    protected function reconcile(
        User $user,
        Backer $backer,
        ?BackerStatus $current,
        ?object $account,
        Group $recurringGroup,
        ?Group $onetimeGroup,
        bool $dryRun,
        bool $baseline
    ): ?BackerChange {
        $previous = $backer->status;
        $oldGroupIds = $user->groups->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $managed = $backer->managedGroupIds();

        $wanted = match ($current) {
            BackerStatus::Recurring => [(int) $recurringGroup->id],
            BackerStatus::OneTime   => $onetimeGroup ? [(int) $onetimeGroup->id] : [],
            null                    => [],
        };

        $add = array_values(array_diff($wanted, $oldGroupIds));
        $remove = array_values(array_intersect(array_diff($managed, $wanted), $oldGroupIds));
        $newManaged = array_values(array_unique(array_merge(array_intersect($managed, $wanted), $add)));
        sort($newManaged);

        $statusChanged = $previous !== $current;

        if (!$statusChanged && !$add && !$remove && $newManaged === $managed) {
            return null;
        }

        $newGroupIds = array_values(array_unique(array_merge(array_diff($oldGroupIds, $remove), $add)));
        sort($newGroupIds);

        if (!$dryRun) {
            $add && $user->groups()->attach($add);
            $remove && $user->groups()->detach($remove);

            if ($current === null && !$newManaged) {
                $backer->exists && $backer->delete();
            } else {
                $backer->status = $current;
                $backer->managed_group_ids = $newManaged;

                if ($statusChanged) {
                    $backer->status_changed_at = Carbon::now();
                }

                $backer->save();
            }

            if ($statusChanged && !$baseline) {
                $event = match (true) {
                    $current === BackerStatus::Recurring                                        => RecurringBackingStarted::class,
                    $previous === BackerStatus::Recurring && $current === BackerStatus::OneTime => RecurringBackingStopped::class,
                    $current === BackerStatus::OneTime                                          => OneTimeBackingStarted::class,
                    default                                                                     => BackingEnded::class,
                };

                $this->events->dispatch(new $event($user, $previous, $current, $account, $oldGroupIds, $newGroupIds));
            } elseif ($add || $remove) {
                $this->events->dispatch(new BackerGroupsChanged($user, $oldGroupIds, $newGroupIds));
            }
        }

        // Forgetting a membership the user no longer has is bookkeeping, not a change to them.
        return ($statusChanged || $add || $remove) ? new BackerChange($user, $previous, $current, $add, $remove) : null;
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
