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

use Flarum\Extend;
use Flarum\Foundation\Paths;
use FoF\OpenCollective\Console\UpdateCommand;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),
    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\Console())
        ->command(UpdateCommand::class)
        // ->schedule(UpdateCommand::class, function (Event $event) {
        //     $paths = resolve(Paths::class);
        //     $event
        //     ->hourly()
        //     ->withoutOverlapping()
        //     ->appendOutputTo($paths->storage.'/logs/fof-open-collective.log');
        // }),
];
