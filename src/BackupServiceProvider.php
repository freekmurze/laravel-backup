<?php

namespace Spatie\Backup;

use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Backup\Commands\BackupCommand;
use Spatie\Backup\Commands\CleanupCommand;
use Spatie\Backup\Commands\ListCommand;
use Spatie\Backup\Commands\MonitorCommand;
use Spatie\Backup\Events\BackupZipWasCreated;
use Spatie\Backup\Helpers\ConsoleOutput;
use Spatie\Backup\Listeners\EncryptBackupArchive;
use Spatie\Backup\Notifications\Channels\Discord\DiscordChannel;
use Spatie\Backup\Notifications\EventHandler;
use Spatie\Backup\Tasks\Cleanup\CleanupStrategy;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class BackupServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-backup')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasCommands([
                BackupCommand::class,
                CleanupCommand::class,
                ListCommand::class,
                MonitorCommand::class,
            ]);
    }

    public function packageBooted()
    {
        $this->app['events']->subscribe(EventHandler::class);
    }

    public function packageRegistered()
    {
        $this->app->singleton(ConsoleOutput::class);

        $this->app->bind(CleanupStrategy::class, config('backup.cleanup.strategy'));

        $this->registerDiscordChannel();
    }

    protected function registerDiscordChannel()
    {
        Notification::resolved(function (ChannelManager $service) {
            $service->extend('discord', function ($app) {
                return new DiscordChannel;
            });
        });
    }
}
