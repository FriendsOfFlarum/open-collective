# Open Collective by FriendsOfFlarum

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/fof/open-collective.svg)](https://packagist.org/packages/fof/open-collective)

A [Flarum](http://flarum.org) extension. Open Collective integration for your Flarum forum.

### Installation

Use [Bazaar](https://discuss.flarum.org/d/5151-flagrow-bazaar-the-extension-marketplace) or install manually with composer:

```sh
composer require fof/open-collective
```

#### Scheduler

You'll need to set up a Cron job.

```
* * * * * cd /path-to-your-project && php flarum schedule:run >> /dev/null 2>&1
```

### Updating

```sh
composer update fof/open-collective
```

### Links

- [Packagist](https://packagist.org/packages/fof/open-collective)
- [GitHub](https://github.com/FriendsOfFlarum/open-collective)

An extension by [FriendsOfFlarum](https://github.com/FriendsOfFlarum).
