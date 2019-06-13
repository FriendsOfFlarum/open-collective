<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) 2019 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective;

use Flarum\Console\Event\Configuring;
use Flarum\Extend;
use FoF\Console\Extend\EnableConsole;
use FoF\Console\Extend\ScheduleCommand;
use FoF\OpenCollective\Console\UpdateCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Events\Dispatcher;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),
    new Extend\Locales(__DIR__.'/resources/locale'),
    new EnableConsole(),
    new ScheduleCommand(function (Schedule $schedule) {
        $schedule->command(UpdateCommand::class)
            ->hourly()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/fof-open-collective.log'));
    }),

    new Extend\Compat(function (Dispatcher $events) {
        $events->listen(Configuring::class, function (Configuring $event) {
            if ($event->app->bound(Schedule::class)) {
                $event->addCommand(UpdateCommand::class);
            }
        });
    }),
];
