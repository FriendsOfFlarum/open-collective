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

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Backer>
 */
class BackerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'           => User::factory(),
            'status'            => null,
            'managed_group_ids' => [],
            'status_changed_at' => null,
        ];
    }

    public function recurring(): static
    {
        return $this->state(['status' => BackerStatus::Recurring->value, 'status_changed_at' => Carbon::now()]);
    }

    public function oneTime(): static
    {
        return $this->state(['status' => BackerStatus::OneTime->value, 'status_changed_at' => Carbon::now()]);
    }

    /**
     * @param array<int> $groupIds Groups the extension added the user to
     */
    public function managing(array $groupIds): static
    {
        return $this->state(['managed_group_ids' => $groupIds]);
    }
}
