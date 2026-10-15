<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective;

use Flarum\Audit\Extend\Audit;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use FoF\OpenCollective\Api\Controller\TestConnectionController;
use FoF\OpenCollective\Console\UpdateCommand;
use FoF\OpenCollective\Data\OpenCollectiveBacker;
use FoF\OpenCollective\Event\AbstractBackerStatusChanged;
use FoF\OpenCollective\Event\BackerGroupsChanged;
use FoF\OpenCollective\Event\BackerMembershipAdopted;
use FoF\OpenCollective\Event\BackingEnded;
use FoF\OpenCollective\Event\OneTimeBackingStarted;
use FoF\OpenCollective\Event\RecurringBackingStarted;
use FoF\OpenCollective\Event\RecurringBackingStopped;
use FoF\OpenCollective\Providers\SupporterGroupsServiceProvider;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),
    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\ServiceProvider())
        ->register(SupporterGroupsServiceProvider::class),

    (new Extend\Routes('api'))
        ->post('/fof/open-collective/test-connection', 'fof-open-collective.test-connection', TestConnectionController::class),

    (new Extend\Console())
        ->command(UpdateCommand::class)
        ->schedule(UpdateCommand::class, function (Event $event) {
            $event
                ->hourly()
                ->withoutOverlapping();
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-audit', function () {
            // user_id and the group ID lists are the keys the Audit browser renders
            // as a username link and group badges, and that `user:` search matches.
            $status = fn (AbstractBackerStatusChanged $event) => [
                'user_id'         => $event->user->id,
                'previous_status' => $event->previous?->value,
                'current_status'  => $event->current?->value,
                'old_group_ids'   => $event->oldGroupIds,
                'new_group_ids'   => $event->newGroupIds,
            ];

            return [
                (new Audit())
                    ->listen(RecurringBackingStarted::class, 'fof_open_collective.recurring_started', $status)
                    ->listen(RecurringBackingStopped::class, 'fof_open_collective.recurring_stopped', $status)
                    ->listen(OneTimeBackingStarted::class, 'fof_open_collective.onetime_started', $status)
                    ->listen(BackingEnded::class, 'fof_open_collective.backing_ended', $status)
                    ->listen(BackerGroupsChanged::class, 'fof_open_collective.groups_changed', fn (BackerGroupsChanged $event) => [
                        'user_id'       => $event->user->id,
                        'old_group_ids' => $event->oldGroupIds,
                        'new_group_ids' => $event->newGroupIds,
                    ])
                    ->listen(BackerMembershipAdopted::class, 'fof_open_collective.membership_adopted', fn (BackerMembershipAdopted $event) => [
                        'user_id'       => $event->user->id,
                        'new_group_ids' => $event->groupIds,
                    ]),
            ];
        })
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())
                ->addType(OpenCollectiveBacker::class),
        ]),
];
