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
use FoF\OpenCollective\Services\BackerMatcher;
use FoF\OpenCollective\Services\GroupSynchronizer;
use GuzzleHttp\Exception\RequestException;
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
    protected $signature = 'fof:open-collective:update {--dry-run : Show what changes would be made without applying them}';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Update groups of Open Collective supporters.';

    protected $prefix;

    /**
     * @var SettingsRepositoryInterface
     */
    private $settings;

    /**
     * @var OpenCollectiveClient
     */
    private $client;

    /**
     * @var BackerMatcher
     */
    private $matcher;

    /**
     * @var GroupSynchronizer
     */
    private $synchronizer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        SettingsRepositoryInterface $settings,
        OpenCollectiveClient $client,
        BackerMatcher $matcher,
        GroupSynchronizer $synchronizer,
        LoggerInterface $logger
    ) {
        parent::__construct();

        $this->settings = $settings;
        $this->client = $client;
        $this->matcher = $matcher;
        $this->synchronizer = $synchronizer;
        $this->logger = $logger;
        $this->prefix = Carbon::now()->format('M d, Y @ h:m A');
    }

    public function handle()
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
            $slug = strtolower($this->settings->get('fof-open-collective.slug'));
            $groupId = $this->settings->get('fof-open-collective.group_id');
            $isLegacyApiKey = (int) $this->settings->get('fof-open-collective.use_legacy_api_key');

            $this->validateSettings($apiKey, $slug, $groupId);

            $group = Group::find((int) $groupId);

            if ($verbose) {
                $this->info('Configuration:');
                $this->info('|> API Type: '.($isLegacyApiKey ? 'Legacy API Key' : 'Personal Token'));
                $this->info('|> Collective: '.$slug);
                $this->info("|> Target group: {$group->name_singular} (ID: {$group->id})");
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

            if ($verbose && count($backers) > 0) {
                $this->line('');
                $this->info('Backer details from Open Collective:');
                foreach ($backers as $backer) {
                    $backerData = $backer->account ?? $backer;
                    $email = $backerData->email ?? 'no email';
                    $this->info("|> Email: $email");
                }
                $this->line('');
            }

            // Match backers to Flarum users
            $backerUsers = $this->matcher->matchBackersToUsers($backers);
            $registeredCount = $backerUsers->count();
            $unregisteredCount = count($backers) - $registeredCount;

            $this->info("|> -> {$registeredCount} registered, {$unregisteredCount} not registered");

            if ($verbose) {
                if ($registeredCount > 0) {
                    $this->line('');
                    $this->info('Matched Flarum users:');

                    // Get backer data for matching
                    $backerEmails = $this->matcher->getBackerEmails($backers)->all();

                    foreach ($backerUsers as $user) {
                        $matchInfo = in_array($user->email, $backerEmails) ? ' [matched by: email]' : '';
                        $this->info("|> #{$user->id} {$user->username} ({$user->email}){$matchInfo}");
                    }
                    $this->line('');
                }

                if ($unregisteredCount > 0) {
                    $this->info('Unmatched backers (not registered on Flarum):');
                    $matchedEmails = $backerUsers->pluck('email')->all();

                    foreach ($backers as $backer) {
                        $backerData = $backer->account ?? $backer;
                        $email = $backerData->email ?? null;

                        if (!$email || !in_array($email, $matchedEmails)) {
                            $emailDisplay = $email ?: 'no email provided';
                            $reason = !$email ? ' (no email to match)' : ' (no matching Flarum user found)';
                            $this->info("|> Email: $emailDisplay{$reason}");
                        }
                    }
                    $this->line('');
                }
            }

            // Synchronize group memberships
            $this->info($dryRun ? 'Calculating group changes...' : 'Applying group changes...');

            $changes = $this->synchronizer->synchronize(
                $group,
                $backerUsers,
                $this->matcher->getBackerEmails($backers)->all(),
                $dryRun,
                $backers
            );

            // Calculate users staying in the group (active backers already in group)
            $usersStaying = $backerUsers->filter(function ($user) use ($group) {
                return $user->groups()->find($group->id) !== null;
            });

            if ($verbose) {
                $this->line('');
                $this->info('Summary:');
                $this->info("|> Users staying in group: {$usersStaying->count()}");
                $this->info("|> Users to remove: {$changes['removed']->count()}");
                $this->info("|> Users to add: {$changes['added']->count()}");
                $this->line('');
            }

            if ($verbose && $usersStaying->count() > 0) {
                $this->info('Users staying in group (active backers):');
                $this->outputUsers($usersStaying, '=');
            }

            if ($changes['removed']->count() > 0) {
                if ($verbose) {
                    $this->info('Removing users from group:');
                }
                $this->outputUsers($changes['removed'], '-');
            }

            if ($changes['added']->count() > 0) {
                if ($verbose) {
                    $this->info('Adding users to group:');
                }
                $this->outputUsers($changes['added'], '+');
            }

            if ($changes['removed']->count() === 0 && $changes['added']->count() === 0) {
                $this->info('No changes needed.');
            }

            $this->info('Done.');
        } catch (RequestException $e) {
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
     * Handle Open Collective API errors gracefully.
     */
    private function handleApiError(RequestException $e): void
    {
        $response = $e->getResponse();
        $statusCode = $response ? $response->getStatusCode() : null;

        $message = 'Open Collective API error';

        if ($statusCode === 401) {
            $message = 'Open Collective API authentication failed. Please check your API token.';
            $this->error($message);
            $this->line('');
            $this->line('To fix this:');
            $this->line('1. Generate a new Personal Token at: https://opencollective.com/applications');
            $this->line('2. Update the token in your admin settings');
        } elseif ($statusCode === 403) {
            $message = 'Open Collective API rate limit exceeded or insufficient permissions.';
            $this->error($message);
        } elseif ($statusCode === 404) {
            $message = 'Open Collective collective not found.';
            $this->error($message);
        } else {
            $this->error('Open Collective API request failed: '.$e->getMessage());
        }

        $this->logger->error('[fof/open-collective] '.$message, [
            'status_code' => $statusCode,
            'exception'   => $e->getMessage(),
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

    protected function outputUsers($users, $prefix)
    {
        foreach ($users as $user) {
            $this->outputUser($user, $prefix);
        }
    }

    protected function outputUser($user, $prefix)
    {
        $this->info("|> $prefix #{$user->id} {$user->username}");
    }

    public function info($string, $verbosity = null)
    {
        parent::info($this->prefix.' | '.$string, $verbosity);
    }

    public function warn($string, $verbosity = null)
    {
        parent::warn($this->prefix.' | '.$string, $verbosity);
    }

    public function error($string, $verbosity = null)
    {
        parent::error($this->prefix.' | '.$string, $verbosity);
    }

    public function line($string, $style = null, $verbosity = null)
    {
        if ($string !== '') {
            parent::line($this->prefix.' | '.$string, $style, $verbosity);
        } else {
            parent::line($string, $style, $verbosity);
        }
    }
}
