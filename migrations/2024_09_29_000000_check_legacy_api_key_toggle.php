<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\Schema\Builder;

return [
    'up' => static function (Builder $schema) {
        // Set the legacy API key toggle if the API key is set before this update.
        // Not sure if API keys work with "Personal-Token" header (reverse is true as of Sept 2024)

        /**
         * @var SettingsRepositoryInterface $settings
         */
        $settings = resolve('flarum.settings');

        $api_key = trim($settings->get('fof-open-collective.api_key', ''));

        if (!empty($api_key)) {
            $settings->set('fof-open-collective.use_legacy_api_key', 1);
        }
    },
    'down' => function (Builder $schema) {
        // down migration
    },
];
