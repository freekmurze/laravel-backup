<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupZipWasCreated;
use Spatie\DbDumper\Compressors\GzipCompressor;

beforeEach(function () {
    Event::fake();

    $this->setNow(2016, 1, 1, 21, 1, 1);

    $this->expectedZipPath = 'mysite/2016-01-01-21-01-01.zip';

    config()->set('backup.backup.destination.disks', [
        'local',
        'secondLocal',
    ]);

    config()->set('backup.backup.source.files.include', [base_path()]);

    config()->set('backup.backup.source.databases', [
        'db1',
        'db2',
    ]);
});

it('can backup only the files', function () {
    $this->artisan('backup:run --only-files')->assertExitCode(0);

    Storage::disk('local')->assertExists($this->expectedZipPath);
    Storage::disk('secondLocal')->assertExists($this->expectedZipPath);
});

it('can backup using a custom filename', function () {
    $this->date = Carbon::create('2016', 1, 1, 9, 1, 1);

    Carbon::setTestNow($this->date);

    config()->set('backup.backup.destination.filename_prefix', 'custom_name_');

    $this->expectedZipPath = 'mysite/custom_name_2016-01-01-09-01-01.zip';

    $this->artisan('backup:run', ['--only-files' => true])->assertExitCode(0);

    Storage::disk('local')->assertExists($this->expectedZipPath);
    Storage::disk('secondLocal')->assertExists($this->expectedZipPath);
});

it('includes files from the local disks in the backup', function () {
    config()->set('backup.backup.source.files.include', [$this->getDiskRootPath('local')]);

    Storage::disk('local')->put('testing-file.txt', 'dummy content');

    $this->artisan('backup:run --only-files')->assertExitCode(0);

    $this->assertFileExistsInZip('local', $this->expectedZipPath, 'testing-file.txt');
});

it('excludes the backup destination from the backup', function () {
    config()->set('backup.backup.source.files.include', [$this->getFullDiskPath('local', 'testing-file.txt')]);

    Storage::disk('local')->put('mysite/testing-file.txt', 'dummy content');

    $this->artisan('backup:run --only-files');

    $this->assertFileDoesntExistsInZip('local', $this->expectedZipPath, 'testing-file.txt');
});

it('can backup using relative path', function () {
    config()->set('backup.backup.source.files.include', [$this->getStubDirectory()]);
    config()->set('backup.backup.source.files.exclude', [$this->getStubDirectory('.dot'), $this->getStubDirectory('file'), $this->getStubDirectory('file1.txt.txt')]);
    config()->set('backup.backup.source.files.relative_path', $this->getStubDirectory());

    $testFiles = [
        '.dotfile',
        '1Mb.file',
        'directory1/',
        'directory1/directory1/',
        'directory1/directory1/file1.txt',
        'directory1/directory1/file2.txt',
        'directory1/file1.txt',
        'directory1/file2.txt',
        'directory2/',
        'directory2/directory1/',
        'directory2/directory1/file1.txt',
        'file1.txt',
        'file2.txt',
        'file3.txt',
    ];

    $this->artisan('backup:run --only-files')->assertExitCode(0);

    $zipFiles = [];
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($this->expectedZipPath));
    foreach (range(0, $zip->numFiles - 1) as $i) {
        $zipFiles[] = $zip->statIndex($i)['name'];
    }
    $zip->close();
    sort($testFiles);
    sort($zipFiles);

    expect($zipFiles)->toBe($testFiles);
});

it('can backup using short relative path', function () {
    config()->set('backup.backup.source.files.include', [$this->getStubDirectory()]);
    config()->set('backup.backup.source.files.relative_path', '/stubs');

    $this->artisan('backup:run --only-files')->assertExitCode(0);

    $zipFile = '';
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($this->expectedZipPath));
    if ($zip->numFiles) {
        $zipFile = $zip->statIndex(0)['name'];
    }
    $zip->close();

    expect($zipFile)->toStartWith(ltrim($this->getStubDirectory(), DIRECTORY_SEPARATOR));
});

it('excludes the temporary directory from the backup', function () {
    $tempDirectoryPath = storage_path('app/backup-temp/temp');

    if (! file_exists($tempDirectoryPath)) {
        mkdir($tempDirectoryPath, 0777, true);
    }
    touch($tempDirectoryPath.DIRECTORY_SEPARATOR.'testing-file-temp.txt');

    $this->artisan('backup:run --only-files')->assertExitCode(0);

    $this->assertFileDoesntExistsInZip('local', $this->expectedZipPath, 'testing-file-temp.txt');
});

it('can backup using a custom filename as option', function () {
    $this->date = Carbon::create('2016', 1, 1, 9, 1, 1);

    Carbon::setTestNow($this->date);

    $filename = 'testing-filename.zip';

    $this->expectedZipPath = 'mysite/'.$filename;

    $this->artisan('backup:run', ['--only-files' => true, '--filename' => $filename])->assertExitCode(0);

    Storage::disk('local')->assertExists($this->expectedZipPath);
    Storage::disk('secondLocal')->assertExists($this->expectedZipPath);
});

it('can backup to a specific disk', function () {
    $this->artisan('backup:run --only-files --only-to-disk=secondLocal')->assertExitCode(0);

    Storage::disk('local')->assertMissing($this->expectedZipPath);
    Storage::disk('secondLocal')->assertExists($this->expectedZipPath);
});

it('can selectively backup db', function () {
    $this
        ->artisan('backup:run --only-db --db-name=db1')
        ->assertExitCode(0);

    Storage::disk('local')->assertExists($this->expectedZipPath);

    $this
        ->artisan('backup:run --only-db --db-name=db2')
        ->assertExitCode(0);

    Storage::disk('local')->assertExists($this->expectedZipPath);

    $this
        ->artisan('backup:run --only-db --db-name=db1 --db-name=db2')
        ->assertExitCode(0);

    Storage::disk('local')->assertExists($this->expectedZipPath);

    $this
        ->artisan('backup:run --only-db --db-name=wrongName')
        ->assertExitCode(1);
});

it('can backup twice a day at same time in 12h clock', function () {
    // first backup
    $this->date = Carbon::create('2016', 1, 1, 9, 1, 1);

    Carbon::setTestNow($this->date);

    $this->expectedZipPath = 'mysite/2016-01-01-09-01-01.zip';

    $this->artisan('backup:run --only-files')->assertExitCode(0);

    Storage::disk('local')->assertExists($this->expectedZipPath);
    Storage::disk('secondLocal')->assertExists($this->expectedZipPath);

    // second backup
    $this->date = Carbon::create('2016', 1, 1, 21, 1, 1);

    Carbon::setTestNow($this->date);

    $this->expectedZipPath = 'mysite/2016-01-01-21-01-01.zip';

    $this->artisan('backup:run --only-files')->assertExitCode(0);

    Storage::disk('local')->assertExists($this->expectedZipPath);
    Storage::disk('secondLocal')->assertExists($this->expectedZipPath);
});

it('will fail when try to backup only the files and only the db', function () {
    $resultCode = Artisan::call('backup:run --only-files --only-db');

    expect($resultCode)->toEqual(1);

    $this->seeInConsoleOutput('Cannot use `only-db` and `only-files` together.');

    Storage::disk('local')->assertMissing($this->expectedZipPath);
    Storage::disk('secondLocal')->assertMissing($this->expectedZipPath);
});

it('will fail when trying to backup a non existing database', function () {
    //use an invalid db name to trigger failure
    Artisan::call('backup:run --only-files --only-db --db-name=wrongName');

    $this->seeInConsoleOutput('Backup failed');
});

it('will fail when trying to backup to an non existing diskname', function () {
    $resultCode = Artisan::call('backup:run --only-to-disk=non-existing-disk');

    expect($resultCode)->toEqual(1);

    $this->seeInConsoleOutput('There is no backup destination with a disk named');

    Storage::disk('local')->assertMissing($this->expectedZipPath);
    Storage::disk('secondLocal')->assertMissing($this->expectedZipPath);
});

it('will fail when there are no files to be backed up', function () {
    config()->set('backup.backup.source.files.include', []);
    config()->set('backup.backup.source.databases', []);

    Artisan::call('backup:run');

    $this->seeInConsoleOutput('There are no files to be backed up');
});

it('avoid full path on database backup', function () {
    config()->set('backup.backup.source.databases', ['db1']);

    $this->setUpDatabase(app());

    $this->artisan('backup:run --only-db')->assertExitCode(0);

    $this->assertExactPathExistsInZip('local', $this->expectedZipPath, 'db-dumps/sqlite-db1-database.sql');

    /*
     * Close the database connection to unlock the sqlite file for deletion.
     * This prevents the errors from other tests trying to delete and recreate the folder.
     */
    app()['db']->disconnect();
});

it('appends the database type to backup file name to prevent overwrite', function () {
    config()->set('backup.backup.source.databases', ['db1', 'db2']);

    $this->setUpDatabase(app());

    $this->artisan('backup:run --only-db')->assertExitCode(0);

    $this->assertFileExistsInZip('local', $this->expectedZipPath, 'sqlite-db1-database.sql');
    $this->assertFileExistsInZip('local', $this->expectedZipPath, 'sqlite-db2-database.sql');

    $this->assertFileExistsInZip('secondLocal', $this->expectedZipPath, 'sqlite-db1-database.sql');
    $this->assertFileExistsInZip('secondLocal', $this->expectedZipPath, 'sqlite-db2-database.sql');

    /*
     * Close the database connection to unlock the sqlite file for deletion.
     * This prevents the errors from other tests trying to delete and recreate the folder.
     */
    app()['db']->disconnect();
});

it('renames database dump file extension when specified', function () {
    config()->set('backup.backup.source.databases', ['db1', 'db2']);
    config()->set('backup.backup.database_dump_file_extension', 'backup');

    $this->setUpDatabase(app());

    $this->artisan('backup:run --only-db')->assertExitCode(0);

    $this->assertFileExistsInZip('local', $this->expectedZipPath, 'sqlite-db1-database.backup');
    $this->assertFileExistsInZip('local', $this->expectedZipPath, 'sqlite-db2-database.backup');
    $this->assertFileDoesntExistsInZip('local', $this->expectedZipPath, 'sqlite-db1-database.sql');
    $this->assertFileDoesntExistsInZip('local', $this->expectedZipPath, 'sqlite-db2-database.sql');

    $this->assertFileExistsInZip('secondLocal', $this->expectedZipPath, 'sqlite-db1-database.backup');
    $this->assertFileExistsInZip('secondLocal', $this->expectedZipPath, 'sqlite-db2-database.backup');
    $this->assertFileDoesntExistsInZip('secondLocal', $this->expectedZipPath, 'sqlite-db1-database.sql');
    $this->assertFileDoesntExistsInZip('secondLocal', $this->expectedZipPath, 'sqlite-db2-database.sql');

    /*
     * Close the database connection to unlock the sqlite file for deletion.
     * This prevents the errors from other tests trying to delete and recreate the folder.
     */
    app()['db']->disconnect();
});

it('appends timestamp to database backup file name', function () {
    config()->set('backup.backup.source.databases', ['db1']);
    config()->set('backup.backup.database_dump_file_timestamp_format', 'Y-m-d-H-i-s');

    $this->setUpDatabase(app());

    $this->artisan('backup:run --only-db')->assertExitCode(0);

    $this->assertExactPathExistsInZip('local', $this->expectedZipPath, 'db-dumps/sqlite-db1-database-2016-01-01-21-01-01.sql');

    /*
     * Close the database connection to unlock the sqlite file for deletion.
     * This prevents the errors from other tests trying to delete and recreate the folder.
     */
    app()['db']->disconnect();
});

it('should trigger the backup failed event', function () {
    // use an invalid dbname to trigger failure
    $this->artisan('backup:run --only-db --db-name=wrongName')->assertExitCode(1);

    Event::assertDispatched(BackupHasFailed::class);
});

it('should omit the backup failed event with no notifications flag', function () {
    //use an invalid dbname to trigger failure
    $this->artisan('backup:run --only-db --db-name=wrongName --disable-notifications')->assertExitCode(1);

    Event::assertNotDispatched(BackupHasFailed::class);
});

it('compresses the database dump', function () {
    config()->set('backup.backup.source.databases', ['sqlite']);
    config()->set('backup.backup.database_dump_compressor', GzipCompressor::class);

    $this->setUpDatabase(app());

    $this->artisan('backup:run --only-db')->assertExitCode(0);

    $this->assertFileExistsInZip('local', $this->expectedZipPath, 'sqlite-sqlite-database.sql.gz');

    /*
     * Close the database connection to unlock the sqlite file for deletion.
     * This prevents the errors from other tests trying to delete and recreate the folder.
     */
    app()['db']->disconnect();
});

it('keeps archive unencrypted without password', function () {
    config()->set('backup.backup.password', null);
    config()->set('backup.backup.source.files.include', [$this->getStubDirectory()]);
    config()->set('backup.backup.source.files.relative_path', $this->getStubDirectory());

    $this->artisan('backup:run --only-files')->assertExitCode(0);
    Storage::disk('local')->assertExists($this->expectedZipPath);

    $zip = new ZipArchive();
    $zip->open(Storage::disk('local')->path($this->expectedZipPath));

    $this->assertEncryptionMethod($zip, ZipArchive::EM_NONE);

    $this->assertTrue($zip->extractTo(Storage::disk('local')->path('temp/extraction')));
    $this->assertValidExtractedFiles();

    $zip->close();
});

/**
 * @param int $algorithm
 */
it('encrypts archive with password', function (int $algorithm) {
    config()->set('backup.backup.password', $this->fakePassword());
    config()->set('backup.backup.encryption', $algorithm);
    config()->set('backup.backup.source.files.include', [$this->getStubDirectory()]);
    config()->set('backup.backup.source.files.relative_path', $this->getStubDirectory());

    $this->artisan('backup:run --only-files')->assertExitCode(0);

    Storage::disk('local')->assertExists($this->expectedZipPath);

    $zip = new ZipArchive();
    $zip->open(Storage::disk('local')->path($this->expectedZipPath));

    $this->assertEncryptionMethod($zip, $algorithm);

    $zip->setPassword($this->fakePassword());
    $this->assertTrue($zip->extractTo(Storage::disk('local')->path('temp/extraction')));
    $this->assertValidExtractedFiles();

    $zip->close();
})->with([
    [ZipArchive::EM_AES_128],
    [ZipArchive::EM_AES_192],
    [ZipArchive::EM_AES_256],
]);

it('can not open encrypted archive without password', function () {
    config()->set('backup.backup.password', $this->fakePassword());

    $this->artisan('backup:run --only-files')->assertExitCode(0);

    Storage::disk('local')->assertExists($this->expectedZipPath);

    $zip = new ZipArchive();
    $zip->open(Storage::disk('local')->path($this->expectedZipPath));

    $this->assertEncryptionMethod($zip, ZipArchive::EM_AES_256);

    expect($zip->extractTo(Storage::disk('local')->path('temp/extraction')))->toBeFalse();

    $zip->close();
});

it('will encrypt backup when notifications are disabled', function () {
    config()->set('backup.backup.password', $this->fakePassword());
    config()->set('backup.backup.source.databases', ['db1']);

    $this->artisan('backup:run --disable-notifications --only-db --db-name=db1 --only-to-disk=local')->assertExitCode(0);
    Storage::disk('local')->assertExists($this->expectedZipPath);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($this->expectedZipPath));
    expect($zip->numFiles)->toBe(1);
    expect($zip->statIndex(0)['encryption_method'])->toBe(ZipArchive::EM_AES_256);
    $zip->close();

    Event::assertNotDispatched(BackupZipWasCreated::class);
});

it('can use different compression methods for backup file', function () {
    config()->set('backup.backup.source.databases', ['db1']);

    // by default (with no destination.compression_method specified), the ZipArchive::CM_DEFLATE is used
    $this->artisan('backup:run --only-db')->assertExitCode(0);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($this->expectedZipPath));
    expect($zip->numFiles)->toBe(1);
    expect($zip->statIndex(0)['comp_method'])->toBe(ZipArchive::CM_DEFLATE);
    $zip->close();

    // check no compression with ZipArchive::CM_STORE method
    config()->set('backup.backup.destination.compression_method', ZipArchive::CM_STORE);
    config()->set('backup.backup.destination.compression_level', 0);

    $this->artisan('backup:run --only-db')->assertExitCode(0);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($this->expectedZipPath));
    expect($zip->numFiles)->toBe(1);
    expect($zip->statIndex(0)['comp_method'])->toBe(ZipArchive::CM_STORE);
    $zip->close();

    // check ZipArchive::CM_DEFLATE method with custom compression level
    config()->set('backup.backup.destination.compression_method', ZipArchive::CM_DEFLATE);
    config()->set('backup.backup.destination.compression_level', 2);

    $this->artisan('backup:run --only-db')->assertExitCode(0);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($this->expectedZipPath));
    expect($zip->numFiles)->toBe(1);
    expect($zip->statIndex(0)['comp_method'])->toBe(ZipArchive::CM_DEFLATE);
    $zip->close();
});

it('excludes the previous local backups from the backup', function () {
    $this->date = Carbon::create('2016', 1, 1, 20, 1, 1);
    Carbon::setTestNow($this->date);
    $this->expectedZipPath = 'mysite/2016-01-01-20-01-01.zip';

    $this->artisan('backup:run --only-files')->assertExitCode(0);

    $this->date = Carbon::create('2016', 1, 1, 21, 1, 1);
    Carbon::setTestNow($this->date);
    $this->expectedZipPath = 'mysite/2016-01-01-21-01-01.zip';

    $this->artisan('backup:run --only-files')->assertExitCode(0);

    $this->assertFileDoesntExistsInZip('local', $this->expectedZipPath, '2016-01-01-20-01-01.zip');
});

it('should try again after encountering an exception when using the tries argument', function () {
    // Use an invalid dbname to trigger failure
    $exitCode = Artisan::call('backup:run --only-db --db-name=wrongName --tries=3');
    $output = Artisan::output();

    expect($exitCode)->toEqual(1);

    $this->assertStringContainsString('Attempt n°2...', $output);
    $this->assertStringContainsString('Attempt n°3...', $output);
});

it('should try again after encountering an exception when using the tries configuration option', function () {
    config()->set('backup.backup.tries', 3);

    // Use an invalid dbname to trigger failure
    $exitCode = Artisan::call('backup:run --only-db --db-name=wrongName');
    $output = Artisan::output();

    expect($exitCode)->toEqual(1);

    $this->assertStringContainsString('Attempt n°2...', $output);
    $this->assertStringContainsString('Attempt n°3...', $output);
});

it('should wait before trying again when retry_delay is configured (with Sleep helper)', function () {
    Sleep::fake();

    config()->set('backup.backup.tries', 3);
    config()->set('backup.backup.retry_delay', 3);

    // Use an invalid dbname to trigger failure
    $exitCode = Artisan::call('backup:run --only-db --db-name=wrongName');
    $output = Artisan::output();

    expect($exitCode)->toEqual(1);

    $this->assertStringContainsString('Attempt n°2...', $output);
    $this->assertStringContainsString('Attempt n°3...', $output);

    Sleep::assertSleptTimes(2);
    Sleep::assertSequence([
        Sleep::for(3)->seconds(),
        Sleep::for(3)->seconds(),
    ]);
});

it('uses connection name in place of database name for dump filename', function () {
    config()->set('backup.backup.source.databases', ['db1']);
    config()->set('backup.backup.database_dump_filename_base', 'connection');

    $this->setUpDatabase(app());

    $this->artisan('backup:run --only-db')->assertExitCode(0);

    $this->assertExactPathExistsInZip('local', $this->expectedZipPath, 'db-dumps/sqlite-db1.sql');

    /*
     * Close the database connection to unlock the sqlite file for deletion.
     * This prevents the errors from other tests trying to delete and recreate the folder.
     */
    app()['db']->disconnect();
});
