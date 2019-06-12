<?php


namespace FoF\OpenCollective\Provider;


use Flarum\Foundation\AbstractServiceProvider;
use FoF\OpenCollective\Console\UpdateCommand;
use Illuminate\Console\Scheduling\Schedule;

class ConsoleProvider extends AbstractServiceProvider
{
    public function register()
    {
        if (!defined('ARTISAN_BINARY')) {
            define('ARTISAN_BINARY', 'flarum');
        }

        $this->app->resolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(UpdateCommand::class)
                ->everyMinute()
//                ->hourly()
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/fof-open-collective.log'));
        });
    }
}