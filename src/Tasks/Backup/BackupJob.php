<?php

namespace Spatie\Backup\Tasks\Backup;

use Exception;
use Carbon\Carbon;
use Spatie\DbDumper\DbDumper;
use Illuminate\Support\Collection;
use Spatie\DbDumper\Databases\Sqlite;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\BackupZipWasCreated;
use Spatie\Backup\Exceptions\InvalidBackupJob;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Spatie\Backup\Events\BackupManifestWasCreated;
use Spatie\Backup\BackupDestination\BackupDestination;

class BackupJob
{
    /** @var \Spatie\Backup\Tasks\Backup\FileSelection */
    protected $fileSelection;

    /** @var \Illuminate\Support\Collection */
    protected $dbDumpers;

    /** @var \Illuminate\Support\Collection */
    protected $backupDestinations;

    /** @var string */
    protected $filename;

    /** @var \Spatie\TemporaryDirectory\TemporaryDirectory */
    protected $temporaryDirectory;

    /** @var bool */
    protected $triggerNotifications = true;

    public function __construct()
    {
        $this->dontBackupFilesystem();
        $this->dontBackupDatabases();
        $this->setDefaultFilename();

        $this->backupDestinations = new Collection();
    }

    public function dontBackupFilesystem(): BackupJob
    {
        $this->fileSelection = FileSelection::create();

        return $this;
    }

    public function dontBackupDatabases(): BackupJob
    {
        $this->dbDumpers = new Collection();

        return $this;
    }

    public function disableNotifications(): BackupJob
    {
        $this->triggerNotifications = false;

        return $this;
    }

    public function setDefaultFilename(): BackupJob
    {
        $this->filename = Carbon::now()->format('Y-m-d-H-i-s').'.zip';

        return $this;
    }

    public function setFileSelection(FileSelection $fileSelection): BackupJob
    {
        $this->fileSelection = $fileSelection;

        return $this;
    }

    public function setDbDumpers(Collection $dbDumpers): BackupJob
    {
        $this->dbDumpers = $dbDumpers;

        return $this;
    }

    public function setFilename(string $filename): BackupJob
    {
        $this->filename = $filename;

        return $this;
    }

    public function onlyBackupTo(string $diskName): BackupJob
    {
        $this->backupDestinations = $this->backupDestinations->filter(function (BackupDestination $backupDestination) use ($diskName) {
            return $backupDestination->diskName() === $diskName;
        });

        if (! count($this->backupDestinations)) {
            throw InvalidBackupJob::destinationDoesNotExist($diskName);
        }

        return $this;
    }

    public function setBackupDestinations(Collection $backupDestinations): BackupJob
    {
        $this->backupDestinations = $backupDestinations;

        return $this;
    }

    public function run()
    {
        $this->temporaryDirectory = (new TemporaryDirectory(storage_path('app/laravel-backup')))
            ->name('temp')
            ->force()
            ->create();

        try {
            if (! count($this->backupDestinations)) {
                throw InvalidBackupJob::noDestinationsSpecified();
            }

            $manifest = $this->createBackupManifest();

            if (! $manifest->count()) {
                throw InvalidBackupJob::noFilesToBeBackedUp();
            }

            $zipFile = $this->createZipContainingEveryFileInManifest($manifest);

            $this->copyToBackupDestinations($zipFile);
        } catch (Exception $exception) {
            consoleOutput()->error("Backup failed because {$exception->getMessage()}.".PHP_EOL.$exception->getTraceAsString());

            $this->triggerNotification(new BackupHasFailed($exception));
        }

        $this->temporaryDirectory->delete();
    }

    protected function createBackupManifest(): Manifest
    {
        $databaseDumps = $this->dumpDatabases();

        consoleOutput()->info('Determining files to backup...');

        $manifest = Manifest::create($this->temporaryDirectory->path('manifest.txt'))
            ->addFiles($databaseDumps)
            ->addFiles($this->filesToBeBackedUp());

        $this->triggerNotification(new BackupManifestWasCreated($manifest));

        return $manifest;
    }

    public function filesToBeBackedUp()
    {
        $this->fileSelection->excludeFilesFrom($this->directoriesUsedByBackupJob());

        return $this->fileSelection->selectedFiles();
    }

    protected function directoriesUsedByBackupJob(): array
    {
        return $this->backupDestinations
            ->filter(function (BackupDestination $backupDestination) {
                return $backupDestination->filesystemType() === 'local';
            })
            ->map(function (BackupDestination $backupDestination) {
                return $backupDestination->disk()->getDriver()->getAdapter()->applyPathPrefix('').$backupDestination->backupName();
            })
            ->each(function (string $backupDestinationDirectory) {
                $this->fileSelection->excludeFilesFrom($backupDestinationDirectory);
            })
            ->push($this->temporaryDirectory->path())
            ->toArray();
    }

    protected function createZipContainingEveryFileInManifest(Manifest $manifest)
    {
        consoleOutput()->info("Zipping {$manifest->count()} files...");

        $pathToZip = $this->temporaryDirectory->path(config('laravel-backup.backup.destination.filename_prefix').$this->filename);

        $zip = Zip::createForManifest($manifest, $pathToZip);

        consoleOutput()->info("Created zip containing {$zip->count()} files. Size is {$zip->humanReadableSize()}");

        $this->triggerNotification(new BackupZipWasCreated($pathToZip));

        return $pathToZip;
    }

    /**
     * Dumps the databases to the given directory.
     * Returns an array with paths to the dump files.
     *
     * @return array
     */
    protected function dumpDatabases(): array
    {
        return $this->dbDumpers->map(function (DbDumper $dbDumper) {
            consoleOutput()->info("Dumping database {$dbDumper->getDbName()}...");

            $dbType = mb_strtolower(basename(str_replace('\\', '/', get_class($dbDumper))));

            $dbName = $dbDumper instanceof Sqlite ? 'database' : $dbDumper->getDbName();

            $fileName = "{$dbType}-{$dbName}.sql";

            $temporaryFilePath = $this->temporaryDirectory->path('db-dumps'.DIRECTORY_SEPARATOR.$fileName);

            $dbDumper->dumpToFile($temporaryFilePath);

            if (config('laravel-backup.backup.gzip_database_dump')) {
                consoleOutput()->info("Gzipping {$dbDumper->getDbName()}...");

                $compressedDumpPath = Gzip::compress($temporaryFilePath);

                return $compressedDumpPath;
            }

            return $temporaryFilePath;
        })->toArray();
    }

    protected function copyToBackupDestinations(string $path)
    {
        $this->backupDestinations->each(function (BackupDestination $backupDestination) use ($path) {
            try {
                consoleOutput()->info("Copying zip to disk named {$backupDestination->diskName()}...");

                $backupDestination->write($path);

                consoleOutput()->info("Successfully copied zip to disk named {$backupDestination->diskName()}.");

                $this->triggerNotification(new BackupWasSuccessful($backupDestination));
            } catch (Exception $exception) {
                consoleOutput()->error("Copying zip failed because: {$exception->getMessage()}.");

                $this->triggerNotification(new BackupHasFailed($exception, $backupDestination ?? null));
            }
        });
    }

    protected function triggerNotification($notification)
    {
        if ($this->triggerNotifications) {
            event($notification);
        }
    }
}
