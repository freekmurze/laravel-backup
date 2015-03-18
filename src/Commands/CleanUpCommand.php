<?php namespace Spatie\Backup\Commands;

use Illuminate\Console\Command;
use Storage;
use Carbon\Carbon;
use Spatie\Backup\FileHelpers\FileSelector;

class CleanUpCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'backup:clean';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean all backups older than specified number of days in config';

    public function fire()
    {
        $path = config('laravel-backup.destination.path');

        $expireDate = Carbon::now()->subDays(config('laravel-backup.expirationDays'));

        $this->info('Start cleaning up back-up files created before ' . $expireDate);
        $this->info('');

        $filesDeleted = 0;

        foreach($this->getTargetFileSystems() as $filesystem)
        {
            $disk = Storage::disk($filesystem);

            $filesToBeDeleted = (new FileSelector($path, $disk))->getFilesOlderThan($expireDate, ['zip']);

            foreach($filesToBeDeleted as $file)
            {
                $modified = Carbon::createFromTimestamp(Storage::lastModified($file));

                Storage::delete($file);
                $this->comment($file . ' deleted because it was '.$modified->diffInDays().' days old.');
                $filesDeleted++;
            }

            $this->comment($filesystem.'-filesystem cleaned up.');
        }

        $this->info('Deleted '.$filesDeleted.' files.');
    }

    /**
     * Get the filesystems to where the database should be dumped.
     *
     * @return array
     */
    protected function getTargetFileSystems()
    {
        $fileSystems = config('laravel-backup.destination.filesystem');

        if (is_array($fileSystems)) {
            return $fileSystems;
        }

        return [$fileSystems];
    }
}
