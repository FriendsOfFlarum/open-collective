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
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the extension knows about one user's backing, and which of their group
 * memberships it owns.
 *
 * @property int               $user_id
 * @property BackerStatus|null $status
 * @property array<int>|null   $managed_group_ids
 * @property Carbon|null       $status_changed_at
 * @property User              $user
 */
class Backer extends AbstractModel
{
    /** @use HasFactory<BackerFactory> */
    use HasFactory;

    protected $table = 'fof_open_collective_backers';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    /** Lets test fixtures upsert by user rather than by every column (PostgreSQL cannot compare the JSON column). */
    public ?array $uniqueKeys = ['user_id'];

    protected $fillable = ['user_id'];

    protected $casts = [
        'status'            => BackerStatus::class,
        'managed_group_ids' => 'array',
        'status_changed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<int>
     */
    public function managedGroupIds(): array
    {
        return array_map('intval', $this->managed_group_ids ?? []);
    }
}
