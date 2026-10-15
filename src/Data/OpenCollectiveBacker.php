<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Data;

use Flarum\Gdpr\Data\Type;
use Flarum\Group\Group;
use FoF\OpenCollective\Backer;

/**
 * A member's backing as this extension records it, for flarum/gdpr.
 *
 * Erasing the record matters beyond the data itself: left behind on an anonymized
 * account, the next sync would see the backer "disappear" and fire BackingEnded,
 * with an audit entry, for someone who asked to be erased.
 */
class OpenCollectiveBacker extends Type
{
    /** Named explicitly: the class name alone ("Backer") would be too generic for GDPR's translation keys. */
    public static function dataType(): string
    {
        return 'OpenCollectiveBacker';
    }

    public function export(): ?array
    {
        $backer = Backer::find($this->user->id);

        if (!$backer) {
            return null;
        }

        return [
            'open-collective/backer.json' => $this->encodeForExport([
                'status'            => $backer->status?->value,
                'status_changed_at' => $backer->status_changed_at?->toIso8601String(),
                'managed_groups'    => Group::query()->whereIn('id', $backer->managedGroupIds())->pluck('name_singular')->all(),
            ]),
        ];
    }

    public function anonymize(): void
    {
        // Nothing in the record is worth keeping once the account is anonymous;
        // GDPR's own User type removes the group memberships afterwards.
        $this->delete();
    }

    public function delete(): void
    {
        Backer::query()->where('user_id', $this->user->id)->delete();
    }
}
