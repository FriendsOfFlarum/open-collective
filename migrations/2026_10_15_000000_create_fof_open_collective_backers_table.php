<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable(
    'fof_open_collective_backers',
    function (Blueprint $table) {
        $table->unsignedInteger('user_id')->primary();
        $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        // Null once a known backer stops backing; kept so ended backings and owned memberships are not forgotten.
        $table->string('status', 16)->nullable();
        // Groups this extension added the user to, and may therefore remove them from.
        $table->json('managed_group_ids')->nullable();
        $table->dateTime('status_changed_at')->nullable();
    }
);
