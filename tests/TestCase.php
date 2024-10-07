<?php

namespace Spatie\Backup\Tests;

use Carbon\Carbon;
use DateTime;
use Exception;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Backup\BackupServiceProvider;
use Spatie\Backup\Tests\TestSupport\FakeFailingHealthCheck;
use ZipArchive;

abstract class TestCase extends Orchestra
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            BackupServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function getEnvironmentSetUp($app)
    {
        $this->initializeTempDirectory();

        config()->set('backup.monitor_backups.0.health_checks', []);

        config()->set('mail.driver', 'log');

        config()->set('database.connections.db1', [
            'driver' => 'sqlite',
            'database' => $this->createSQLiteDatabase('database1.sqlite'),
        ]);

        config()->set('database.connections.db2', [
            'driver' => 'sqlite',
            'database' => $this->createSQLiteDatabase('database2.sqlite'),
        ]);

        config()->set('database.default', 'db1');

        Storage::fake('local');
        Storage::fake('secondLocal');
    }

    protected function setUpDatabase(Application $app)
    {
        touch($this->getTempDirectory().'/database.sqlite');

        Schema::create('test_models', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
    }

    protected function seeInConsoleOutput(string $expectedText): void
    {
        $consoleOutput = $this->app[Kernel::class]->output();

        $this->assertStringContainsString(
            $expectedText,
            $consoleOutput,
            "Did not see `{$expectedText}` in console output: `$consoleOutput`"
        );
    }

    protected function doNotSeeInConsoleOutput(string $unexpectedText): void
    {
        $consoleOutput = $this->app[Kernel::class]->output();

        $this->assertNotContains(
            $unexpectedText,
            $consoleOutput,
            "Did not expect to see `{$unexpectedText}` in console output: `$consoleOutput`"
        );
    }

    protected function assertFileExistsInZip(string $diskName, string $zipPath, string $fileName): void
    {
        $this->assertTrue(
            $this->fileExistsInZip($diskName, $zipPath, $fileName),
            "Failed to assert that {$zipPath} contains a file name {$fileName}"
        );
    }

    protected function assertFileDoesntExistsInZip(string $diskName, string $zipPath, string $fileName): void
    {
        $this->assertFalse(
            $this->fileExistsInZip($diskName, $zipPath, $fileName),
            "Failed to assert that {$zipPath} doesn't contain a file name {$fileName}"
        );
    }

    protected function fileExistsInZip(string $diskName, string $zipPath, string $fileName): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($this->getFullDiskPath($diskName, $zipPath)) === true) {
            return $zip->locateName($fileName, ZipArchive::FL_NODIR) !== false;
        }

        return false;
    }

    protected function assertExactPathExistsInZip(string $diskName, string $zipPath, string $fullPath)
    {
        $this->assertTrue(
            $this->exactPathExistsInZip($diskName, $zipPath, $fullPath),
            "Failed to assert that {$zipPath} contains a path {$fullPath}"
        );
    }

    protected function exactPathExistsInZip(string $diskName, string $zipPath, string $fullPath): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($this->getFullDiskPath($diskName, $zipPath)) === true) {
            foreach (range(0, $zip->numFiles - 1) as $i) {
                if ($zip->statIndex($i)['name'] == str_replace('/', DIRECTORY_SEPARATOR, $fullPath)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function createFileOnDisk(string $diskName, string $filePath, DateTime $date): string
    {
        Storage::disk($diskName)->put($filePath, 'dummy content');

        touch($this->getFullDiskPath($diskName, $filePath), $date->getTimestamp());

        return $filePath;
    }

    protected function create1MbFileOnDisk(string $diskName, string $filePath, DateTime $date): void
    {
        $sourceFile = $this->getStubDirectory().'/1Mb.file';

        Storage::disk($diskName)->put($filePath, file_get_contents($sourceFile));

        touch($this->getFullDiskPath($diskName, $filePath), $date->getTimestamp());
    }

    protected function getFullDiskPath(string $diskName, string $filePath): string
    {
        return $this->getDiskRootPath($diskName).DIRECTORY_SEPARATOR.$filePath;
    }

    protected function getDiskRootPath(string $diskName): string
    {
        return storage_path("framework/testing/disks/{$diskName}");
    }

    public function setNow(int $year, int $month, int $day, int $hour = 0, int $minutes = 0, int $seconds = 0): void
    {
        $date = Carbon::create($year, $month, $day, $hour, $minutes, $seconds);

        Carbon::setTestNow($date);
    }

    public function getStubDirectory(?string $file = null): string
    {
        return __DIR__.'/stubs'.($file ? '/'.$file : '');
    }

    public function createSQLiteDatabase(string $fileName): string
    {
        $directory = $this->getTempDirectory().'/'.dirname($fileName);

        File::makeDirectory($directory, 0755, true, true);

        $sourceFile = $this->getStubDbDirectory().'/database.sqlite';

        $fullPath = $this->getTempDirectory().'/'.$fileName;

        copy($sourceFile, $fullPath);

        touch($fullPath);

        return $fullPath;
    }

    public function getStubDbDirectory(?string $file = null): string
    {
        return __DIR__.'/stubs-db'.($file ? '/'.$file : '');
    }

    public function getTempDirectory(?string $file = null): string
    {
        return __DIR__.'/temp'.($file ? '/'.$file : '');
    }

    public function initializeTempDirectory()
    {
        $this->initializeDirectory($this->getTempDirectory());
    }

    public function initializeDirectory(string $directory): void
    {
        File::deleteDirectory($directory);

        File::makeDirectory($directory);

        $this->addGitignoreTo($directory);
    }

    public function addGitignoreTo(string $directory): void
    {
        $fileName = "{$directory}/.gitignore";

        $fileContents = '*'.PHP_EOL.'!.gitignore';

        File::put($fileName, $fileContents);
    }

    public function fakeBackup(): self
    {
        $this->createFileOnDisk('local', 'mysite/test1.zip', now()->subSecond());

        return $this;
    }

    public function makeHealthCheckFail(?Exception $customException = null): self
    {
        FakeFailingHealthCheck::$reason = $customException;

        config()->set('backup.monitor_backups.0.health_checks', [FakeFailingHealthCheck::class]);

        return $this;
    }

    public function assertEncryptionMethod(ZipArchive $zip, int $algorithm): void
    {
        foreach (range(0, $zip->numFiles - 1) as $i) {
            expect($zip->statIndex($i)['encryption_method'])->toBe($algorithm);
        }
    }

    public function assertValidExtractedFiles(): void
    {
        foreach (['file1.txt', 'file2.txt', 'file3.txt'] as $filename) {
            $filepath = 'temp/extraction/'.$filename;
            Storage::disk('local')->assertExists($filepath);
            expect(Storage::disk('local')->get($filepath))->toBe('lorum ipsum');
        }
    }

    public function fakePassword(): string
    {
        return '24dsjF6BPjWgUfTu';
    }
}
