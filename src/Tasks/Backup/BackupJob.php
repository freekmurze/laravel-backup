<?php

namespace Spatie\Backup\Tasks\Backup;

use Carbon\Carbon;
use Exception;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Events\BackupManifestWasCreated;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\BackupZipWasCreated;
use Spatie\Backup\Events\DumpingDatabase;
use Spatie\Backup\Exceptions\BackupFailed;
use Spatie\Backup\Exceptions\InvalidBackupJob;
use Spatie\DbDumper\Compressors\GzipCompressor;
use Spatie\DbDumper\Databases\MongoDb;
use Spatie\DbDumper\Databases\Sqlite;
use Spatie\DbDumper\DbDumper;
use Spatie\SignalAwareCommand\Facades\Signal;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class BackupJob
{
    public const FILENAME_FORMAT = 'Y-m-d-H-i-s.\z\i\p';

    protected FileSelection $fileSelection;

    protected Collection $dbDumpers;

    protected Collection $backupDestinations;

    protected string $filename;

    protected TemporaryDirectory $temporaryDirectory;

    protected bool $sendNotifications = true;

    protected bool $signals = true;

    public function __construct()
    {
        $this
            ->dontBackupFilesystem()
            ->dontBackupDatabases()
            ->setDefaultFilename();

        $this->backupDestinations = new Collection;
    }

    public function dontBackupFilesystem(): self
    {
        $this->fileSelection = FileSelection::create();

        return $this;
    }

    public function onlyDbName(array $allowedDbNames): self
    {
        $this->dbDumpers = $this->dbDumpers->filter(
            fn (DbDumper $dbDumper, string $connectionName) => in_array($connectionName, $allowedDbNames)
        );

        return $this;
    }

    public function dontBackupDatabases(): self
    {
        $this->dbDumpers = new Collection;

        return $this;
    }

    public function disableNotifications(): self
    {
        $this->sendNotifications = false;

        return $this;
    }

    public function disableSignals(): self
    {
        $this->signals = false;

        return $this;
    }

    public function setDefaultFilename(): self
    {
        $this->filename = Carbon::now()->format(static::FILENAME_FORMAT);

        return $this;
    }

    public function setFileSelection(FileSelection $fileSelection): self
    {
        $this->fileSelection = $fileSelection;

        return $this;
    }

    public function setDbDumpers(Collection $dbDumpers): self
    {
        $this->dbDumpers = $dbDumpers;

        return $this;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function onlyBackupTo(string $diskName): self
    {
        $this->backupDestinations = $this->backupDestinations->filter(
            fn (BackupDestination $backupDestination) => $backupDestination->diskName() === $diskName
        );

        if (! count($this->backupDestinations)) {
            throw InvalidBackupJob::destinationDoesNotExist($diskName);
        }

        return $this;
    }

    public function setBackupDestinations(Collection $backupDestinations): self
    {
        $this->backupDestinations = $backupDestinations;

        return $this;
    }

    /**
     * @throws Exception
     */
    public function run(): void
    {
        $temporaryDirectoryPath = config('backup.backup.temporary_directory') ?? storage_path('app/backup-temp');

        $this->temporaryDirectory = (new TemporaryDirectory($temporaryDirectoryPath))
            ->name('temp')
            ->force()
            ->create()
            ->empty();

        if ($this->signals) {
            Signal::handle(SIGINT, function (Command $command) {
                $command->info('Cleaning up temporary directory...');

                $this->temporaryDirectory->delete();
            });
        }

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
            consoleOutput()->error("Backup failed because: {$exception->getMessage()}.".PHP_EOL.$exception->getTraceAsString());

            $this->temporaryDirectory->delete();

            throw BackupFailed::from($exception);
        }

        $this->temporaryDirectory->delete();

        if ($this->signals) {
            Signal::clearHandlers(SIGINT);
        }
    }

    protected function createBackupManifest(): Manifest
    {
        $databaseDumps = $this->dumpDatabases();

        consoleOutput()->info('Determining files to backup...');

        $manifest = Manifest::create($this->temporaryDirectory->path('manifest.txt'))
            ->addFiles($databaseDumps)
            ->addFiles($this->filesToBeBackedUp());

        $this->sendNotification(new BackupManifestWasCreated($manifest));

        return $manifest;
    }

    public function filesToBeBackedUp(): Generator
    {
        $this->fileSelection->excludeFilesFrom($this->directoriesUsedByBackupJob());

        return $this->fileSelection->selectedFiles();
    }

    protected function directoriesUsedByBackupJob(): array
    {
        return $this->backupDestinations
            ->filter(fn (BackupDestination $backupDestination) => $backupDestination->filesystemType() === 'localfilesystemadapter')
            ->map(
                fn (BackupDestination $backupDestination) => $backupDestination->disk()->path('').$backupDestination->backupName()
            )
            ->each(fn (string $backupDestinationDirectory) => $this->fileSelection->excludeFilesFrom($backupDestinationDirectory))
            ->push($this->temporaryDirectory->path())
            ->toArray();
    }

    protected function createZipContainingEveryFileInManifest(Manifest $manifest): string
    {
        consoleOutput()->info("Zipping {$manifest->count()} files and directories...");

        $pathToZip = $this->temporaryDirectory->path(config('backup.backup.destination.filename_prefix').$this->filename);

        $zip = Zip::createForManifest($manifest, $pathToZip);

        consoleOutput()->info("Created zip containing {$zip->count()} files and directories. Size is {$zip->humanReadableSize()}");

        $this->sendNotification(new BackupZipWasCreated($pathToZip));

        return $pathToZip;
    }

    /**
     * Dumps the databases to the given directory.
     * Returns an array with paths to the dump files.
     */
    protected function dumpDatabases(): array
    {
        return $this->dbDumpers
            ->map(function (DbDumper $dbDumper, $key) {
                consoleOutput()->info("Dumping database {$dbDumper->getDbName()}...");

                $dbType = mb_strtolower(basename(str_replace('\\', '/', get_class($dbDumper))));

                if (config('backup.backup.database_dump_filename_base') === 'connection') {
                    $dbName = $key;
                } elseif ($dbDumper instanceof Sqlite) {
                    $dbName = $key.'-database';
                } else {
                    $dbName = $dbDumper->getDbName();
                }

                $timeStamp = '';
                if ($timeStampFormat = config('backup.backup.database_dump_file_timestamp_format')) {
                    $timeStamp = '-'.Carbon::now()->format($timeStampFormat);
                }

                $fileName = "{$dbType}-{$dbName}{$timeStamp}.{$this->getExtension($dbDumper)}";

                if (config('backup.backup.gzip_database_dump')) {
                    $dbDumper->useCompressor(new GzipCompressor);
                    $fileName .= '.'.$dbDumper->getCompressorExtension();
                }

                if ($compressor = config('backup.backup.database_dump_compressor')) {
                    $dbDumper->useCompressor(new $compressor);
                    $fileName .= '.'.$dbDumper->getCompressorExtension();
                }

                $temporaryFilePath = $this->temporaryDirectory->path('db-dumps'.DIRECTORY_SEPARATOR.$fileName);

                event(new DumpingDatabase($dbDumper));

                $dbDumper->dumpToFile($temporaryFilePath);

                return $temporaryFilePath;
            })
            ->toArray();
    }

    /**
     * @throws Exception
     */
    protected function copyToBackupDestinations(string $path): void
    {
        $this->backupDestinations
            ->each(function (BackupDestination $backupDestination) use ($path) {
                try {
                    if (! $backupDestination->isReachable()) {
                        throw new Exception("Could not connect to disk {$backupDestination->diskName()} because: {$backupDestination->connectionError()}");
                    }

                    consoleOutput()->info("Copying zip to disk named {$backupDestination->diskName()}...");

                    $backupDestination->write($path);

                    consoleOutput()->info("Successfully copied zip to disk named {$backupDestination->diskName()}.");

                    $this->sendNotification(new BackupWasSuccessful($backupDestination));
                } catch (Exception $exception) {
                    consoleOutput()->error("Copying zip failed because: {$exception->getMessage()}.");

                    throw BackupFailed::from($exception)->destination($backupDestination);
                }
            });
    }

    protected function sendNotification($notification): void
    {
        if ($this->sendNotifications) {
            rescue(
                fn () => event($notification),
                fn () => consoleOutput()->error('Sending notification failed')
            );
        }
    }

    protected function getExtension(DbDumper $dbDumper): string
    {
        if ($extension = config('backup.backup.database_dump_file_extension')) {
            return $extension;
        }

        return $dbDumper instanceof MongoDb
            ? 'archive'
            : 'sql';
    }
}
