# Open Collective by FriendsOfFlarum

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/fof/open-collective.svg)](https://packagist.org/packages/fof/open-collective) [![OpenCollective](https://img.shields.io/badge/opencollective-fof-blue.svg)](https://opencollective.com/fof/donate) [![Donate](https://img.shields.io/badge/donate-datitisev-important.svg)](https://datitisev.me/donate)

A [Flarum](http://flarum.org) extension that automatically synchronizes your Open Collective backers with Flarum user groups.

## Features

- 🔄 **Automatic Synchronization**: Hourly checks for new and removed backers
- 👥 **Smart User Matching**: Matches backers via email addresses
- 🔒 **Safe Group Management**: Only manages users it adds, won't interfere with manually assigned groups
- 📝 **Detailed Logging**: Tracks all changes in dedicated log files
- 🧪 **Dry-Run Mode**: Preview changes before applying them
- 📊 **Verbose Output**: Detailed information about the synchronization process

## How It Works

This extension connects your Open Collective account with your Flarum forum by:

1. **Fetching Backers**: Queries Open Collective's GraphQL API to retrieve your current backers
2. **Matching Users**: Identifies Flarum users by matching email addresses from Open Collective backers with Flarum user emails
3. **Managing Groups**: Automatically adds backers to a designated Flarum group and removes users who are no longer backers
4. **Tracking Changes**: Logs all additions and removals to help you monitor the process

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
2. Create a new Personal Token
3. Copy the token (you won't be able to see it again)

**Legacy API Keys**: If you have an existing legacy API key, you can continue using it by enabling "Use Legacy Application API Key" in the settings. However, it's recommended to migrate to a Personal Token.

### 2. Configure the Extension

In your Flarum admin panel, navigate to the Open Collective extension settings:

- **Personal Token** (or **API Key** if using legacy): Paste your Open Collective token
- **Collective Slug**: Enter your collective slug (e.g., if your collective is at `opencollective.com/your-collective`, enter `your-collective`)
- **Group**: Select which Flarum group to assign to backers

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

#### Verbose Output

Get detailed information about the synchronization process:

```bash
php flarum fof:open-collective:update -v
```

Verbose mode shows:
- Configuration details (API type, collective slug, target group)
- All backers from Open Collective with their emails
- Matched Flarum users with match method (email)
- Unmatched backers who couldn't be linked to Flarum accounts
- Summary of users staying, being added, and being removed

Combine both options for detailed preview:

```bash
php flarum fof:open-collective:update --dry-run -v
```

### Viewing Logs

Check the synchronization logs:

```bash
tail -f storage/logs/fof-open-collective.log
```

Log output includes:
- Number of backers found on Open Collective
- Number of backers matched to Flarum users
- Users added to the group (with `+ #userID username`)
- Users removed from the group (with `- #userID username`)
- Any API errors or configuration issues

## How User Matching Works

The extension matches Open Collective backers to Flarum users by comparing email addresses. For a backer to be matched:

- The backer must have a public email address on their Open Collective profile
- A Flarum user must exist with that same email address
- The Flarum user's email must be confirmed

## Important Notes

- The extension only removes the group from users it previously added. It won't affect users who were manually added to the group.
- Users must have the same email address as their Open Collective account.
- The Open Collective API has rate limits. The extension checks once per hour to stay well within these limits.
- Only individual backers are synchronized (not organizational backers).

## For Developers: Event System

This extension dispatches events when backers are added or removed, allowing other extensions to react to these changes.

### Available Events

#### `FoF\OpenCollective\Event\BackerAdded`

Dispatched when a user is added to the backers group.

**Properties:**
- `$user` (Flarum\User\User) - The Flarum user who was added
- `$backerData` (object|null) - Open Collective backer data including:
  - `email` - Backer's email address
  - Other fields from the Open Collective API

**Example listener:**

```php
use Flarum\Extend;
use FoF\OpenCollective\Event\BackerAdded;

return [
    (new Extend\Event)
        ->listen(BackerAdded::class, function (BackerAdded $event) {
            $user = $event->user;
            $email = $event->backerData->email ?? null;

            // Your custom logic here
            // e.g., send a welcome email, grant additional permissions, etc.
        }),
];
```

#### `FoF\OpenCollective\Event\BackerRemoved`

Dispatched when a user is removed from the backers group (backing ended).

**Properties:**
- `$user` (Flarum\User\User) - The Flarum user who was removed

**Example listener:**

```php
use Flarum\Extend;
use FoF\OpenCollective\Event\BackerRemoved;

return [
    (new Extend\Event)
        ->listen(BackerRemoved::class, function (BackerRemoved $event) {
            $user = $event->user;

            // Your custom logic here
            // e.g., send a thank you message, revoke special access, etc.
        }),
];
```

### Notes on Events

- Events are **not dispatched** when running with the `--dry-run` flag
- Events fire after the database changes have been made
- The `BackerAdded` event includes raw Open Collective data for additional context
- These events only fire for automated changes, not manual group assignments

## Troubleshooting

### No backers are being synced

- Verify your token is correct and has proper permissions
- Check that the collective slug matches your Open Collective account exactly
- Ensure the cron job is running (`schedule:run`)
- Check logs in `storage/logs/fof-open-collective.log`

### Some backers aren't being matched

- Ensure backers have public email addresses on their Open Collective profiles
- Verify that corresponding Flarum users exist with the same email addresses
- Check that Flarum user emails are confirmed

### Authentication errors

- If using a Personal Token, ensure it was created correctly
- If using a legacy API Key, consider migrating to a Personal Token
- Check that the token hasn't expired or been revoked

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
