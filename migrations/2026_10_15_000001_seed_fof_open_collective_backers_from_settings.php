<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/*
 * Moves the managed-user lists from settings into the backers table. Status is
 * left null: the first sync afterwards records it without firing events.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $setting = fn (string $key) => $db->table('settings')->where('key', $key)->value('value');

        $managed = [];

        foreach (['fof-open-collective.users' => 'fof-open-collective.group_id', 'fof-open-collective.onetime_users' => 'fof-open-collective.onetime_group_id'] as $listKey => $groupKey) {
            $groupId = (int) $setting($groupKey);

            if (!$groupId) {
                continue;
            }

            foreach ((array) json_decode($setting($listKey) ?? '[]') as $userId) {
                $managed[(int) $userId][] = $groupId;
            }
        }

        $existing = $db->table('users')->whereIn('id', array_keys($managed))->pluck('id')->all();

        foreach ($existing as $userId) {
            $db->table('fof_open_collective_backers')->insert([
                'user_id'           => $userId,
                'status'            => null,
                'managed_group_ids' => json_encode(array_values(array_unique($managed[$userId]))),
            ]);
        }

        $db->table('settings')->whereIn('key', ['fof-open-collective.users', 'fof-open-collective.onetime_users'])->delete();
    },

    'down' => function (Builder $schema) {
        $db = $schema->getConnection();
        $groupIds = [
            'fof-open-collective.users'         => (int) $db->table('settings')->where('key', 'fof-open-collective.group_id')->value('value'),
            'fof-open-collective.onetime_users' => (int) $db->table('settings')->where('key', 'fof-open-collective.onetime_group_id')->value('value'),
        ];
        $rows = $db->table('fof_open_collective_backers')->get(['user_id', 'managed_group_ids']);

        foreach ($groupIds as $listKey => $groupId) {
            $userIds = $rows->filter(fn ($row) => in_array($groupId, json_decode($row->managed_group_ids ?? '[]', true), true))->pluck('user_id')->values();
            $db->table('settings')->updateOrInsert(['key' => $listKey], ['value' => $userIds->toJson()]);
        }
    },
];
