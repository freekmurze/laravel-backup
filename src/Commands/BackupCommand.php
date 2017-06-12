<?php

namespace Spatie\Backup\Commands;

use Exception;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Exceptions\InvalidCommand;
use Spatie\Backup\Tasks\Backup\BackupJobFactory;

class BackupCommand extends BaseCommand
{
    /** @var string */
    protected $signature = 'backup:run {--filename=} {--only-db} {--only-files} {--only-to-disk=} {--no-notifications}';

    /** @var string */
    protected $description = 'Run the backup.';

    public function handle()
    {
        consoleOutput()->comment('Starting backup...');

        $noNotifications = $this->option('no-notifications');

        try {
            $this->guardAgainstInvalidOptions();

            $backupJob = BackupJobFactory::createFromArray(config('laravel-backup'));

            if ($this->option('only-db')) {
                $backupJob->dontBackupFilesystem();
            }

            if ($this->option('only-files')) {
                $backupJob->dontBackupDatabases();
            }

            if ($this->option('only-to-disk')) {
                $backupJob->onlyBackupTo($this->option('only-to-disk'));
            }

            if ($this->option('filename')) {
                $backupJob->setFilename($this->option('filename'));
            }

            if ($noNotifications) {
                $backupJob->disableNotifications();
            }

            $backupJob->run();

            consoleOutput()->comment('Backup completed!');
        } catch (Exception $exception) {
            consoleOutput()->error("Backup failed because: {$exception->getMessage()}.");

            if (!$noNotifications) {
                event(new BackupHasFailed($exception));
            }

            return -1;
        }
    }

    protected function guardAgainstInvalidOptions()
    {
        if ($this->option('only-db') && $this->option('only-files')) {
            throw InvalidCommand::create('Cannot use `only-db` and `only-files` together');
        }
    }
}
