<?php


namespace FoF\OpenCollective\Console;


use Carbon\Carbon;
use EUAutomation\GraphQL\Client;
use Exception;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Console\Command;
use UnexpectedValueException;

class UpdateCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $signature = 'fof:open-collective:update';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Update groups of Open Collective supporters.';

    protected $prefix;

    /**
     * @var SettingsRepositoryInterface
     */
    private $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        parent::__construct();

        $this->settings = $settings;

        $this->prefix = Carbon::now()->format('M d, Y @ h:m A');
    }

    public function handle() {
        $this->line('');

        $apiKey = $this->settings->get('fof-open-collective.api_key');
        $slug = strtolower($this->settings->get('fof-open-collective.slug'));
        $groupId = $this->settings->get('fof-open-collective.group_id');
        $group = isset($groupId) ? Group::find((int) $groupId) : null;

        if (!isset($apiKey) || empty($apiKey)) {
            throw new UnexpectedValueException("Open Collective API key must be provided");
        } else if (!isset($slug) || empty($slug)) {
            throw new UnexpectedValueException("Collective slug must be provided");
        } else if (!isset($group)) {
            throw new UnexpectedValueException("Invalid group ID: '$groupId'");
        }

        $this->info('Retrieving Open Collective members...');

        // Retrieve emails from Open Collective GraphQL API

        $client = new Client('https://api.opencollective.com/graphql/v2');
        $json = $client->json('
            query collective($slug: String) {
              collective(slug: $slug) {
                name
                slug

                members(role: BACKER, accountType: INDIVIDUAL) {
                  nodes {
                    account {
                      ... on Individual {
                        email
                      }

                      ... on Organization {
                        email
                      }
                    }
                  }
                }
              }
            }
        ', ['slug' => $slug], ['Api-Key' => $apiKey]);

        if (!isset($json->data->collective)) {
            throw new Exception("Collective '$slug' not found");
        }

        $emails = collect($json->data->collective->members->nodes)
            ->map(function ($m) {
                return $m->account->email;
            })
            ->filter()
            ->unique();

        $this->info("|> found {$emails->count()}");

        // Remove group from users that have it but shouldn't

        $users = $group->users()->whereNotIn('email', $emails)->get();

        $group->users()->detach($users->map->id);

        $this->info('Applying group changes...');

        foreach($users as $user) {
            $this->info("|> - #$user->id $user->username");
        }

        if ($emails->isEmpty()) {
            $this->info('Done.');
            return;
        }

        // Add group to users that should have it

        $users = User::where('is_email_confirmed', true)
            ->whereIn('email', $emails);

        if ($users->count() != 0) {
            $users->get()->each(function ($user) use ($group, &$num) {
                if (!$user->groups()->find($group->id)) {
                    $this->info("|> + #$user->id $user->username");
                    $user->groups()->attach($group->id);
                }
            });
        }

        $this->info('Done.');
    }

    public function info($string, $verbosity = null)
    {
        parent::info($this->prefix." | ".$string, $verbosity);
    }
}