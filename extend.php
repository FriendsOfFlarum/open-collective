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
use Flarum\Foundation\Application;
use FoF\Console\Extend\EnableConsole;
use FoF\OpenCollective\Console\UpdateCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Events\Dispatcher;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),
    new Extend\Locales(__DIR__ . '/resources/locale'),
    new EnableConsole,

    new Extend\Compat(function (Application $app, Dispatcher $events) {
        $app->register(Provider\ConsoleProvider::class);

        $events->listen(Configuring::class, function (Configuring $event) {
            if ($event->app->bound(Schedule::class)) {
                $event->addCommand(UpdateCommand::class);
            }
        });
    })
];
