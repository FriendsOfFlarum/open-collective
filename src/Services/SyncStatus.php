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

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The outcome of the last applied sync, kept so the admin page can show when the
 * sync last ran and why it failed, rather than leaving that in the logs.
 */
class SyncStatus
{
    /** Stored as JSON; admins receive every setting in their payload, so the panel reads it without an endpoint. */
    public const SETTING = 'fof-open-collective.last_sync';

    /** The command refused to apply an empty result (see --force). */
    public const NO_BACKERS = 'no_backers';

    public const CONFIGURATION = 'configuration';

    public const UNEXPECTED = 'unexpected';

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function recordSuccess(int $backers, int $changes): void
    {
        $this->store(['ok' => true, 'kind' => null, 'message' => null, 'backers' => $backers, 'changes' => $changes]);
    }

    /**
     * @param string $kind An {@see \FoF\OpenCollective\Api\OpenCollectiveException} kind, or one of this class's constants
     */
    public function recordFailure(string $kind, string $message): void
    {
        $this->store(['ok' => false, 'kind' => $kind, 'message' => $message, 'backers' => null, 'changes' => null]);
    }

    /**
     * @return array{at: string, ok: bool, kind: string|null, message: string|null, backers: int|null, changes: int|null}|null
     */
    public function last(): ?array
    {
        $status = json_decode((string) $this->settings->get(self::SETTING), true);

        return is_array($status) ? $status : null;
    }

    /**
     * @param array<string, mixed> $status
     */
    protected function store(array $status): void
    {
        $this->settings->set(self::SETTING, json_encode(['at' => Carbon::now()->toIso8601String()] + $status));
    }
}
