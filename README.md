# Open Collective by FriendsOfFlarum

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/fof/open-collective.svg)](https://packagist.org/packages/fof/open-collective) [![OpenCollective](https://img.shields.io/badge/opencollective-fof-blue.svg)](https://opencollective.com/fof/donate) [![Donate](https://img.shields.io/badge/donate-datitisev-important.svg)](https://datitisev.me/donate)

A [Flarum](http://flarum.org) extension that automatically synchronizes your Open Collective backers with Flarum user groups.

## Features

- 🔄 **Automatic Synchronization**: Hourly checks for new and removed backers
- 👥 **Smart User Matching**: Matches backers via email addresses
- 🎯 **Dual Group Support**: Separate groups for recurring and one-time backers
- 🔄 **Status-Based Categorization**: Automatically moves past recurring backers to one-time group
- 🔒 **Safe Group Management**: Only manages users it adds, won't interfere with manually assigned groups
- 🩺 **Sync Status and Connection Test**: See the last sync's result on the settings page and test a token before saving it
- 📝 **Audit Log**: With [Audit](https://docs.flarum.org/extensions/audit) enabled, every status and group change the sync makes is recorded
- 🧪 **Dry-Run Mode**: Preview changes before applying them
- 📊 **Verbose Output**: Detailed information about the synchronization process

## How It Works

This extension connects your Open Collective account with your Flarum forum by:

1. **Fetching Backers**: Queries Open Collective's GraphQL API to retrieve your current backers (active recurring, cancelled recurring, and one-time contributors)
2. **Categorizing Backers**: Separates backers into two groups:
   - **Recurring backers**: Users with active monthly or yearly subscriptions
   - **One-time backers**: Users who made one-time contributions or whose recurring subscription has ended
3. **Matching Users**: Identifies Flarum users by matching email addresses from Open Collective backers with Flarum user emails
4. **Managing Groups**: Automatically adds backers to designated Flarum groups and removes users who are no longer backers
5. **Handling Transitions**: When a recurring backer's subscription ends, they are automatically moved to the one-time backers group
6. **Tracking Changes**: Remembers each backer's status, fires an event when it changes, and records every change in the audit log when [Audit](https://docs.flarum.org/extensions/audit) is enabled

## Installation

Install with composer:

```sh
composer require fof/open-collective:"*"
php flarum migrate
php flarum cache:clear
```

### Required: Flarum Scheduler

This extension requires [Flarum's scheduler](https://docs.flarum.org/scheduler/) to be set up and running. The extension will automatically check for backer updates every hour.

Add this cron job to your server:

```
* * * * * cd /path-to-your-project && php flarum schedule:run >> /dev/null 2>&1
```

## Configuration

After installation, configure the extension in your Flarum admin panel:

### 1. Create an Open Collective Personal Token

1. Go to [Open Collective Applications](https://opencollective.com/applications)
2. Create a new Personal Token with the **`orders`** and **`email`** scopes
3. Copy the token (you won't be able to see it again)

The token can only see what its owner can see, so create it from an account that is an **admin of the collective**. Without the `orders` scope the sync fails; without the `email` scope Open Collective hides every backer's email and nobody can be matched.

**Legacy API Keys**: If you have an existing legacy API key, you can continue using it by enabling "Use Legacy Application API Key" in the settings. However, it's recommended to migrate to a Personal Token.

### 2. Configure the Extension

In your Flarum admin panel, navigate to the Open Collective extension settings:

- **Personal Token** (or **API Key** if using legacy): Paste your Open Collective token
- **Collective Slug**: Enter your collective slug (e.g., if your collective is at `opencollective.com/your-collective`, enter `your-collective`)
- **Recurring Backers Group**: Select which Flarum group to assign to recurring backers (required)
- **One-Time Backers Group**: Optionally select a group for one-time backers and past recurring backers (optional)

**Note**: If you only configure the recurring backers group, all backers (both recurring and one-time) will be added to that single group. If you configure both groups, the extension will automatically categorize backers based on their subscription status.

Save the settings, and the extension will start syncing on the next scheduled run.

## Usage

Once configured, the extension runs automatically every hour. You can also manually trigger an update:

```bash
php flarum fof:open-collective:update
```

### Command Options

#### Dry-Run Mode

Preview what changes would be made without actually applying them:

```bash
php flarum fof:open-collective:update --dry-run
```

This is useful for testing your configuration before letting the extension make real changes to your groups.

#### Force Mode

If Open Collective reports no backers at all while the extension still manages group members, the sync stops without changing anything, because removing everyone is far more likely to be a bad API response than a real change. If your collective really has no backers left, confirm it with:

```bash
php flarum fof:open-collective:update --force
```

#### Verbose Output

Get detailed information about the synchronization process:

```bash
php flarum fof:open-collective:update -v
```

Verbose mode shows:
- Configuration details (API type, collective slug, target groups)
- Total number of backers and breakdown by type (recurring vs one-time)
- All backers from Open Collective with their emails and type
- Matched Flarum users for each group
- Unmatched backers who couldn't be linked to Flarum accounts
- Summary of users staying, being added, removed, and moved between groups

Combine both options for detailed preview:

```bash
php flarum fof:open-collective:update --dry-run -v
```

### Monitoring the Sync

- **Status**: the top of the extension's settings page shows when the sync last ran, and why it failed if it did. It also warns when the last run was more than two hours ago, which usually means Flarum's scheduler is not running.
- **Test connection**: the button on the same page checks the token and slug currently in the form, saved or not: that the token is accepted, that it can read orders, and that it can see backer emails. It also shows which Open Collective account the token belongs to.
- **Changes**: with the [Audit](https://docs.flarum.org/extensions/audit) extension enabled, every change the sync makes is recorded in the audit log: a backer starting or stopping a recurring backing, becoming a one-time backer, no longer being a backer, or being restored to a group they were missing from. Search the log with `action:fof_open_collective.*`, or `user:<username>` for one person.
- **Errors**: failed syncs (a rejected token, a missing scope, an unreachable API) are written to Flarum's log in `storage/logs/`.
- **Manually**: run the command yourself, with `--dry-run -v` to see what it would change without changing anything.

The first sync after installing or upgrading records every backer's current status without announcing them as new backers. Only group memberships it restores on that run are recorded.

## How User Matching Works

The extension matches Open Collective backers to Flarum users by comparing email addresses. For a backer to be matched:

- The backer must have a public email address on their Open Collective profile
- A Flarum user must exist with that same email address
- The Flarum user's email must be confirmed

## Important Notes

- **Safe Group Management**: The extension only removes the group from users it previously added. It won't affect users who were manually added to the group.
- **Email Matching**: Users must have the same email address as their Open Collective account.
- **Backer Categorization**:
  - Users with active monthly or yearly subscriptions are placed in the recurring backers group
  - Users who made one-time contributions or whose subscription has ended are placed in the one-time backers group
  - When a recurring subscription ends, the user is automatically moved to the one-time group (if configured)
- **Rate Limits**: The Open Collective API has rate limits. The extension checks once per hour to stay well within these limits.
- **Email Privacy**: Some backers may have private email addresses on Open Collective. These backers cannot be matched to Flarum users automatically.

## For Developers: Event System

The extension remembers each matched user's backer status (none, one-time or recurring) and fires an event whenever it changes. Events describe the backing, not the groups: they fire for every matched user, including members an admin added to a group by hand, and whether or not a one-time group is configured.

### Status events

| Event | Fired when |
|---|---|
| `FoF\OpenCollective\Event\RecurringBackingStarted` | A user becomes a recurring backer, new or upgrading from a one-time backing |
| `FoF\OpenCollective\Event\RecurringBackingStopped` | A recurring backer's subscription is cancelled or paused; they remain a past, one-time backer |
| `FoF\OpenCollective\Event\OneTimeBackingStarted` | A user who was not a backer makes a one-time contribution |
| `FoF\OpenCollective\Event\BackingEnded` | A user is no longer a backer at all, for example after a refund |

All four extend `AbstractBackerStatusChanged` and implement `BackerStatusChanged`, so you can listen to the interface to react to every change. Each carries:

- `$user` (`Flarum\User\User`)
- `$previous`, `$current` (`FoF\OpenCollective\BackerStatus|null`): the status before and after; `null` means not a backer
- `$account` (`object|null`): the Open Collective account (`id`, `email`); `null` when the backing ended
- `$oldGroupIds`, `$newGroupIds` (`int[]`): the user's groups before and after this sync

### Group repairs

`FoF\OpenCollective\Event\BackerGroupsChanged` fires when the sync changes a user's groups without their status changing, such as re-adding a backer who was missing from their group. It carries `$user`, `$oldGroupIds` and `$newGroupIds`.

### Example listener

```php
use Flarum\Extend;
use FoF\OpenCollective\Event\RecurringBackingStopped;

return [
    (new Extend\Event())
        ->listen(RecurringBackingStopped::class, function (RecurringBackingStopped $event) {
            // e.g. thank $event->user for their support so far
        }),
];
```

### Notes on Events

- Events fire after the database changes have been made, and never in `--dry-run` mode.
- The first sync after installing or upgrading records statuses without status events, so existing backers are not announced as new. Group repairs on that run still fire `BackerGroupsChanged`.
- Group memberships are only removed where the extension added them. Members an admin added by hand still get status events but keep their groups.

### Upgrading from 1.x

`BackerAdded` and `BackerRemoved` were removed in 2.0. `BackerAdded` maps to `RecurringBackingStarted` or `OneTimeBackingStarted`; `BackerRemoved` maps to `RecurringBackingStopped` or `BackingEnded`.

## Troubleshooting

### No backers are being synced

- Verify your token is correct and has proper permissions
- Check that the collective slug matches your Open Collective account exactly
- Ensure the cron job is running (`schedule:run`)
- Check Flarum's log in `storage/logs/` for errors, and the audit log for changes

### Some backers aren't being matched

- Ensure backers have public email addresses on their Open Collective profiles
- Verify that corresponding Flarum users exist with the same email addresses
- Check that Flarum user emails are confirmed

### Authentication errors

- **"Open Collective rejected the token"**: the token has expired or been revoked. Create a new one.
- **"The Open Collective token is missing a required scope"**: the token lacks the `orders` or `email` scope. Edit the token's scopes on Open Collective, or create a new one.
- **"The Open Collective token cannot read backer emails"**: the token lacks the `email` scope.
- If using a legacy API Key, consider migrating to a Personal Token

## Updating

```sh
composer update fof/open-collective
php flarum migrate
php flarum cache:clear
```

## Links

[![OpenCollective](https://img.shields.io/badge/donate-friendsofflarum-44AEE5?style=for-the-badge&logo=open-collective)](https://opencollective.com/fof/donate) [![GitHub](https://img.shields.io/badge/donate-datitisev-ea4aaa?style=for-the-badge&logo=github)](https://datitisev.me/donate/github)

- [Packagist](https://packagist.org/packages/fof/open-collective)
- [GitHub](https://github.com/FriendsOfFlarum/open-collective)
- [Discuss](https://discuss.flarum.org/d/22256)

An extension by [FriendsOfFlarum](https://github.com/FriendsOfFlarum).
