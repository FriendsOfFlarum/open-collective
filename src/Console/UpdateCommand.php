<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Console;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\OpenCollective\Api\OpenCollectiveClient;
use FoF\OpenCollective\Api\OpenCollectiveException;
use FoF\OpenCollective\Backer;
use FoF\OpenCollective\BackerStatus;
use FoF\OpenCollective\Services\BackerChange;
use FoF\OpenCollective\Services\BackerMatcher;
use FoF\OpenCollective\Services\BackerSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use UnexpectedValueException;

class UpdateCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $signature = 'fof:open-collective:update
        {--dry-run : Show what changes would be made without applying them}
        {--force : Apply changes even when Open Collective reports no backers at all}';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Update groups of Open Collective supporters.';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected OpenCollectiveClient $client,
        protected BackerMatcher $matcher,
        protected BackerSynchronizer $synchronizer,
        protected LoggerInterface $logger
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->line('');

        $dryRun = (bool) $this->option('dry-run');
        $verbose = $this->getOutput()->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        try {
            $apiKey = $this->settings->get('fof-open-collective.api_key');
            $slug = strtolower((string) $this->settings->get('fof-open-collective.slug'));
            $recurringGroupId = $this->settings->get('fof-open-collective.group_id');
            $onetimeGroupId = $this->settings->get('fof-open-collective.onetime_group_id');
            $isLegacyApiKey = (bool) (int) $this->settings->get('fof-open-collective.use_legacy_api_key');

            $this->validateSettings($apiKey, $slug, $recurringGroupId);

            $recurringGroup = Group::findOrFail((int) $recurringGroupId);
            $onetimeGroup = $onetimeGroupId ? Group::find((int) $onetimeGroupId) : null;

            if ($onetimeGroupId && !$onetimeGroup) {
                throw new UnexpectedValueException("Invalid one-time group ID: '$onetimeGroupId'");
            }

            if ($verbose) {
                $this->info('Configuration:');
                $this->info('|> API Type: '.($isLegacyApiKey ? 'Legacy API Key' : 'Personal Token'));
                $this->info('|> Collective: '.$slug);
                $this->info("|> Recurring backers group: {$recurringGroup->name_singular} (ID: {$recurringGroup->id})");
                $this->info('|> One-time backers group: '.($onetimeGroup ? "{$onetimeGroup->name_singular} (ID: {$onetimeGroup->id})" : 'Not configured'));
                $this->line('');
            }

            if ($isLegacyApiKey) {
                $this->warn('Using legacy API key. Please consider switching to a personal token.');
            }

            $this->info("Retrieving Open Collective members for '$slug'...");

            $result = $this->client->fetchBackers($apiKey, $slug, $isLegacyApiKey);
            $backers = $result['backers'];

            $this->info('|> '.count($backers)." backers of {$result['collective']}");

            // An empty result ends every known backing in one run. That is right for
            // a collective that truly lost all its backers, but far more often it
            // means the API answered oddly, so it needs an explicit --force.
            if (count($backers) === 0 && !$this->option('force') && Backer::query()->exists()) {
                $message = 'Open Collective returned no backers, which would end every known backing. No changes made; re-run with --force if this is correct.';
                $this->error($message);
                $this->logger->error('[fof/open-collective] '.$message);

                return 1;
            }

            $categorized = $this->matcher->categorizeBackers($backers);
            $this->info('|> '.count($categorized['recurring']).' recurring backers');
            $this->info('|> '.count($categorized['onetime']).' one-time backers');

            if ($verbose && count($backers) > 0) {
                $this->line('');
                $this->info('Backer details from Open Collective:');

                foreach ($backers as $backer) {
                    $email = $backer->fromAccount->email ?? 'no email';
                    $this->info("|> Email: $email [{$this->backerType($backer)}]");
                }

                $this->line('');
            }

            if ($this->synchronizer->isBaseline()) {
                $this->info('First sync: recording current backer statuses without firing backer events.');
            }

            $this->info($dryRun ? 'Calculating changes...' : 'Applying changes...');

            $changes = $this->synchronizer->synchronize($backers, $recurringGroup, $onetimeGroup, $dryRun);
            $groupNames = Group::query()
                ->whereIn('id', $changes->flatMap(fn (BackerChange $change) => [...$change->addedGroupIds, ...$change->removedGroupIds])->unique()->values())
                ->pluck('name_singular', 'id');

            foreach ($changes as $change) {
                $this->info($this->describe($change, $groupNames));
            }

            $this->info($changes->isEmpty() ? 'No changes needed.' : $changes->count().' users '.($dryRun ? 'would change' : 'changed').'.');
            $this->info('Done.');
        } catch (OpenCollectiveException $e) {
            $this->handleApiError($e);

            return 1;
        } catch (UnexpectedValueException $e) {
            $this->error($e->getMessage());
            $this->logger->error('[fof/open-collective] Configuration error: '.$e->getMessage());

            return 1;
        } catch (Throwable $e) {
            $this->error('An unexpected error occurred: '.$e->getMessage());
            $this->logger->error('[fof/open-collective] Unexpected error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return 1;
        }

        return 0;
    }

    /**
     * @param Collection<int, string> $groupNames Group names by ID
     */
    protected function describe(BackerChange $change, Collection $groupNames): string
    {
        $parts = [];

        if ($change->statusChanged()) {
            $parts[] = $this->statusLabel($change->previous).' → '.$this->statusLabel($change->current);
        }

        foreach ($change->addedGroupIds as $id) {
            $parts[] = '+'.($groupNames[$id] ?? "group #$id");
        }

        foreach ($change->removedGroupIds as $id) {
            $parts[] = '-'.($groupNames[$id] ?? "group #$id");
        }

        return "|> #{$change->user->id} {$change->user->username}: ".implode(', ', $parts);
    }

    protected function statusLabel(?BackerStatus $status): string
    {
        return match ($status) {
            BackerStatus::Recurring => 'recurring',
            BackerStatus::OneTime   => 'one-time',
            null                    => 'none',
        };
    }

    /**
     * Explain an Open Collective failure in terms an admin can act on.
     */
    protected function handleApiError(OpenCollectiveException $e): void
    {
        $message = match ($e->kind) {
            OpenCollectiveException::UNAUTHORIZED     => 'Open Collective rejected the token. It may have expired or been revoked.',
            OpenCollectiveException::FORBIDDEN        => 'The Open Collective token is missing a required scope. It needs the "orders" and "email" scopes.',
            OpenCollectiveException::EMAILS_HIDDEN    => 'The Open Collective token cannot read backer emails. It needs the "email" scope.',
            OpenCollectiveException::NOT_FOUND        => 'Open Collective collective not found. Check the collective slug.',
            OpenCollectiveException::RATE_LIMITED     => 'Open Collective rate limit exceeded. The next run will try again.',
            OpenCollectiveException::NETWORK          => 'Could not reach Open Collective.',
            OpenCollectiveException::INVALID_RESPONSE => 'Open Collective returned an unexpected response.',
            default                                   => 'Open Collective API request failed.',
        };

        $this->error($message.' ('.$e->getMessage().')');

        if (in_array($e->kind, [OpenCollectiveException::UNAUTHORIZED, OpenCollectiveException::FORBIDDEN, OpenCollectiveException::EMAILS_HIDDEN], true)) {
            $this->line('');
            $this->line('To fix this:');
            $this->line('1. Create a Personal Token with the "orders" and "email" scopes at https://opencollective.com/applications');
            $this->line('   (the token owner must be an admin of the collective)');
            $this->line('2. Update the token in your admin settings');
        }

        $this->logger->error('[fof/open-collective] '.$message, [
            'kind'      => $e->kind,
            'exception' => $e->getMessage(),
        ]);
    }

    /**
     * @throws UnexpectedValueException
     */
    protected function validateSettings(?string $apiKey, ?string $slug, ?string $groupId): void
    {
        if (empty($apiKey)) {
            throw new UnexpectedValueException('Open Collective API key must be provided');
        }

        if (empty($slug)) {
            throw new UnexpectedValueException('Collective slug must be provided');
        }

        if (!$groupId || !Group::find((int) $groupId)) {
            throw new UnexpectedValueException("Invalid group ID: '$groupId'");
        }
    }

    protected function backerType(object $backer): string
    {
        return in_array($backer->frequency ?? null, ['MONTHLY', 'YEARLY'], true) && ($backer->status ?? null) === 'ACTIVE'
            ? 'recurring'
            : 'one-time';
    }

    /**
     * Prefix every non-empty line with the current time, so a captured run shows
     * when it happened. info(), warn() and error() all route here.
     */
    public function line($string, $style = null, $verbosity = null)
    {
        if ($string !== '') {
            $string = Carbon::now()->format('M d, Y @ h:i A').' | '.$string;
        }

        parent::line($string, $style, $verbosity);
    }
}
