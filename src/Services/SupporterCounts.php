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

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Backers a supporters page cannot list by name: incognito ones, and others with
 * no matching forum account. Aggregate counts only, refreshed by each sync.
 */
class SupporterCounts
{
    public const SETTING = 'fof-open-collective.counts';

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    /**
     * @param array{recurring: int, onetime: int}|null $private   Null keeps the previous private counts
     * @param array{recurring: int, onetime: int}      $unmatched
     */
    public function record(?array $private, array $unmatched): void
    {
        $this->settings->set(self::SETTING, json_encode([
            'private'   => $private ?? $this->get()['private'],
            'unmatched' => $unmatched,
        ]));
    }

    /**
     * @return array{private: array{recurring: int, onetime: int}, unmatched: array{recurring: int, onetime: int}}
     */
    public function get(): array
    {
        $stored = json_decode((string) $this->settings->get(self::SETTING), true);
        $zero = ['recurring' => 0, 'onetime' => 0];

        return [
            'private'   => array_map('intval', array_merge($zero, (array) ($stored['private'] ?? []))),
            'unmatched' => array_map('intval', array_merge($zero, (array) ($stored['unmatched'] ?? []))),
        ];
    }
}
