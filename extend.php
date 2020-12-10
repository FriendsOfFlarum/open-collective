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

use Flarum\Extend;
use Flarum\Foundation\Paths;
use FoF\Components\Extend\AddFofComponents;
use FoF\Console\Extend\EnableConsole;
use FoF\Console\Extend\ScheduleCommand;
use FoF\OpenCollective\Console\UpdateCommand;
use Illuminate\Console\Scheduling\Schedule;

return [
    new AddFofComponents(),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),
    new Extend\Locales(__DIR__.'/resources/locale'),

    new EnableConsole(),
    new ScheduleCommand(function (Schedule $schedule) {
        $paths = app()->make(Paths::class);
        $schedule->command(UpdateCommand::class)
            ->hourly()
            ->withoutOverlapping()
            ->appendOutputTo($paths->storage.('/logs/fof-open-collective.log'));
    }),

    (new Extend\Console())
        ->command(UpdateCommand::class),
];
