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
use FoF\OpenCollective\Services\BackerMatcher;
use FoF\OpenCollective\Services\GroupSynchronizer;
use Illuminate\Console\Command;
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
        private SettingsRepositoryInterface $settings,
        private OpenCollectiveClient $client,
        private BackerMatcher $matcher,
        private GroupSynchronizer $synchronizer,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->line('');

        $dryRun = $this->option('dry-run');
        $verbose = $this->getOutput()->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        try {
            // Load and validate settings
            $apiKey = $this->settings->get('fof-open-collective.api_key');
            $slug = strtolower((string) $this->settings->get('fof-open-collective.slug'));
            $recurringGroupId = $this->settings->get('fof-open-collective.group_id');
            $onetimeGroupId = $this->settings->get('fof-open-collective.onetime_group_id');
            $isLegacyApiKey = (int) $this->settings->get('fof-open-collective.use_legacy_api_key');

            $this->validateSettings($apiKey, $slug, $recurringGroupId);

            $recurringGroup = Group::find((int) $recurringGroupId);
            $onetimeGroup = $onetimeGroupId ? Group::find((int) $onetimeGroupId) : null;

            if ($onetimeGroupId && !$onetimeGroup) {
                throw new UnexpectedValueException("Invalid one-time group ID: '$onetimeGroupId'");
            }

            if ($verbose) {
                $this->info('Configuration:');
                $this->info('|> API Type: '.($isLegacyApiKey ? 'Legacy API Key' : 'Personal Token'));
                $this->info('|> Collective: '.$slug);
                $this->info("|> Recurring backers group: {$recurringGroup->name_singular} (ID: {$recurringGroup->id})");
                if ($onetimeGroup) {
                    $this->info("|> One-time backers group: {$onetimeGroup->name_singular} (ID: {$onetimeGroup->id})");
                } else {
                    $this->info('|> One-time backers group: Not configured');
                }
                $this->line('');
            }

            if ($isLegacyApiKey) {
                $this->warn('Using legacy API key. Please consider switching to a personal token.');
            }

            $this->info("Retrieving Open Collective members for '$slug'...");

            // Fetch backers from Open Collective
            $result = $this->client->fetchBackers($apiKey, $slug, (bool) $isLegacyApiKey);
            $backers = $result['backers'];
            $collectiveName = $result['collective'];

            $this->info('|> '.count($backers)." backers of {$collectiveName}");

            // An empty result removes every managed member in one run. That is right
            // for a collective that truly lost all its backers, but far more often it
            // means the API answered oddly, so it needs an explicit --force.
            if (count($backers) === 0 && !$this->option('force') && ($this->synchronizer->getManagedUsers()->isNotEmpty() || $this->synchronizer->getManagedOnetimeUsers()->isNotEmpty())) {
                $message = 'Open Collective returned no backers, which would remove every managed group member. No changes made; re-run with --force if this is correct.';
                $this->error($message);
                $this->logger->error('[fof/open-collective] '.$message);

                return 1;
            }

            // Categorize backers by type
            $categorized = $this->matcher->categorizeBackers($backers);
            $recurringBackers = $categorized['recurring'];
            $onetimeBackers = $categorized['onetime'];

            $this->info('|> '.count($recurringBackers).' recurring backers');
            $this->info('|> '.count($onetimeBackers).' one-time backers');

            if ($verbose && count($backers) > 0) {
                $this->line('');
                $this->info('Backer details from Open Collective:');
                foreach ($backers as $backer) {
                    $email = $backer->fromAccount->email ?? 'no email';
                    $this->info("|> Email: $email [{$this->backerType($backer)}]");
                }
                $this->line('');
            }

            // Match backers to Flarum users
            $recurringUsers = $this->matcher->matchBackersOfTypeToUsers($recurringBackers);
            $onetimeUsers = $this->matcher->matchBackersOfTypeToUsers($onetimeBackers);

            $recurringRegisteredCount = $recurringUsers->count();
            $onetimeRegisteredCount = $onetimeUsers->count();
            $totalRegistered = $recurringRegisteredCount + $onetimeRegisteredCount;
            $totalUnregistered = count($backers) - $totalRegistered;

            $this->info("|> -> {$recurringRegisteredCount} recurring registered, {$onetimeRegisteredCount} one-time registered");
            $this->info("|> -> {$totalUnregistered} not registered");

            if ($verbose) {
                if ($totalRegistered > 0) {
                    $this->line('');
                    $this->info('Matched Flarum users:');

                    // Show recurring users
                    if ($recurringRegisteredCount > 0) {
                        $this->info('  Recurring backers:');
                        foreach ($recurringUsers as $user) {
                            $this->info("|>   #{$user->id} {$user->username} ({$user->email})");
                        }
                    }

                    // Show one-time users
                    if ($onetimeRegisteredCount > 0) {
                        $this->info('  One-time backers:');
                        foreach ($onetimeUsers as $user) {
                            $this->info("|>   #{$user->id} {$user->username} ({$user->email})");
                        }
                    }
                    $this->line('');
                }

                if ($totalUnregistered > 0) {
                    $this->info('Unmatched backers (not registered on Flarum):');
                    $matchedEmails = $recurringUsers->pluck('email')->merge($onetimeUsers->pluck('email'))->map(fn ($email) => strtolower($email))->all();

                    foreach ($backers as $backer) {
                        $email = $backer->fromAccount->email ?? null;

                        if (!$email || !in_array(strtolower($email), $matchedEmails, true)) {
                            $emailDisplay = $email ?: 'no email provided';
                            $reason = !$email ? ' (no email to match)' : ' (no matching Flarum user found)';
                            $this->info("|> Email: $emailDisplay [{$this->backerType($backer)}]{$reason}");
                        }
                    }
                    $this->line('');
                }
            }

            // Synchronize group memberships
            $this->info($dryRun ? 'Calculating group changes...' : 'Applying group changes...');

            $recurringEmails = $this->matcher->getBackerEmails($recurringBackers)->all();
            $onetimeEmails = $this->matcher->getBackerEmails($onetimeBackers)->all();

            $changes = $this->synchronizer->synchronizeBothGroups(
                $recurringGroup,
                $onetimeGroup,
                $recurringUsers,
                $onetimeUsers,
                $recurringEmails,
                $onetimeEmails,
                $dryRun,
                $recurringBackers,
                $onetimeBackers
            );

            $recurringChanges = $changes['recurring'];
            $onetimeChanges = $changes['onetime'];

            // Calculate users staying in groups
            $recurringStaying = $recurringUsers->filter(function ($user) use ($recurringGroup) {
                return $user->groups()->find($recurringGroup->id) !== null;
            });

            $onetimeStaying = collect();
            if ($onetimeGroup) {
                $onetimeStaying = $onetimeUsers->filter(function ($user) use ($onetimeGroup) {
                    return $user->groups()->find($onetimeGroup->id) !== null;
                });
            }

            if ($verbose) {
                $this->line('');
                $this->info('Summary:');
                $this->info("RECURRING GROUP ({$recurringGroup->name_singular}):");
                $this->info("|> Users staying in group: {$recurringStaying->count()}");
                $this->info("|> Users to remove: {$recurringChanges['removed']->count()}");
                $this->info("|> Users to add: {$recurringChanges['added']->count()}");
                if ($onetimeGroup && $recurringChanges['moved_to_onetime']->count() > 0) {
                    $this->info("|> Users moved to one-time group: {$recurringChanges['moved_to_onetime']->count()}");
                }

                if ($onetimeGroup) {
                    $this->line('');
                    $this->info("ONE-TIME GROUP ({$onetimeGroup->name_singular}):");
                    $this->info("|> Users staying in group: {$onetimeStaying->count()}");
                    $this->info("|> Users to remove: {$onetimeChanges['removed']->count()}");
                    $this->info("|> Users to add: {$onetimeChanges['added']->count()}");
                }
                $this->line('');
            }

            // Output recurring group changes
            if ($verbose && $recurringStaying->count() > 0) {
                $this->info('Users staying in recurring group:');
                $this->outputUsers($recurringStaying, '=');
            }

            if ($recurringChanges['removed']->count() > 0) {
                if ($verbose) {
                    $this->info('Removing users from recurring group:');
                }
                $this->outputUsers($recurringChanges['removed'], '-');
            }

            if ($recurringChanges['moved_to_onetime']->count() > 0) {
                $this->info('Moving users from recurring to one-time group:');
                $this->outputUsers($recurringChanges['moved_to_onetime'], '→');
            }

            if ($recurringChanges['added']->count() > 0) {
                if ($verbose) {
                    $this->info('Adding users to recurring group:');
                }
                $this->outputUsers($recurringChanges['added'], '+');
            }

            // Output one-time group changes
            if ($onetimeGroup) {
                if ($verbose && $onetimeStaying->count() > 0) {
                    $this->info('Users staying in one-time group:');
                    $this->outputUsers($onetimeStaying, '=');
                }

                if ($onetimeChanges['removed']->count() > 0) {
                    if ($verbose) {
                        $this->info('Removing users from one-time group:');
                    }
                    $this->outputUsers($onetimeChanges['removed'], '-');
                }

                if ($onetimeChanges['added']->count() > 0) {
                    if ($verbose) {
                        $this->info('Adding users to one-time group:');
                    }
                    $this->outputUsers($onetimeChanges['added'], '+');
                }
            }

            $totalChanges = $recurringChanges['removed']->count() + $recurringChanges['added']->count() +
                            $recurringChanges['moved_to_onetime']->count();
            if ($onetimeGroup) {
                $totalChanges += $onetimeChanges['removed']->count() + $onetimeChanges['added']->count();
            }

            if ($totalChanges === 0) {
                $this->info('No changes needed.');
            }

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
     * Validate configuration settings.
     *
     * @throws UnexpectedValueException
     */
    private function validateSettings(?string $apiKey, ?string $slug, ?string $groupId): void
    {
        if (!isset($apiKey) || empty($apiKey)) {
            throw new UnexpectedValueException('Open Collective API key must be provided');
        }

        if (!isset($slug) || empty($slug)) {
            throw new UnexpectedValueException('Collective slug must be provided');
        }

        $group = isset($groupId) ? Group::find((int) $groupId) : null;
        if (!isset($group)) {
            throw new UnexpectedValueException("Invalid group ID: '$groupId'");
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int, \Flarum\User\User> $users
     */
    protected function outputUsers($users, string $prefix): void
    {
        foreach ($users as $user) {
            $this->outputUser($user, $prefix);
        }
    }

    /**
     * @param \Flarum\User\User $user
     */
    protected function outputUser($user, string $prefix): void
    {
        $this->info("|> $prefix #{$user->id} {$user->username}");
    }

    protected function backerType(object $backer): string
    {
        return in_array($backer->frequency ?? null, ['MONTHLY', 'YEARLY'], true) && ($backer->status ?? null) === 'ACTIVE'
            ? 'recurring'
            : 'one-time';
    }

    /**
     * Prefix every non-empty line with the current time, so the appended scheduler
     * log shows when each run happened. info(), warn() and error() all route here.
     */
    public function line($string, $style = null, $verbosity = null)
    {
        if ($string !== '') {
            $string = Carbon::now()->format('M d, Y @ h:i A').' | '.$string;
        }

        parent::line($string, $style, $verbosity);
    }
}
