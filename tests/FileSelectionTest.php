<?php

use Spatie\Backup\Tasks\Backup\FileSelection;

beforeEach(function () {
    $this->sourceDirectory = $this->getStubDirectory();
});

it('can select all the files in a directory and subdirectories', function () {
    $fileSelection = new FileSelection($this->sourceDirectory);

    $testFiles = getTestFiles([
        '.dot',
        '.dot/file1.txt',
        '.dotfile',
        '1Mb.file',
        'directory1',
        'directory1/directory1',
        'directory1/directory1/file1.txt',
        'directory1/directory1/file2.txt',
        'directory1/file1.txt',
        'directory1/file2.txt',
        'directory2',
        'directory2/directory1',
        'directory2/directory1/file1.txt',
        'file',
        'file1.txt',
        'file1.txt.txt',
        'file2.txt',
        'file3.txt',
    ]);
    $selectedFiles = iterator_to_array($fileSelection->selectedFiles());

    assertSameArray($testFiles, $selectedFiles);
});

it('can exclude files from a given subdirectory', function () {
    $fileSelection = (new FileSelection($this->sourceDirectory))
        ->excludeFilesFrom("{$this->sourceDirectory}/directory1");

    $testFiles = getTestFiles([
        '.dot',
        '.dot/file1.txt',
        '.dotfile',
        '1Mb.file',
        'directory2',
        'directory2/directory1',
        'directory2/directory1/file1.txt',
        'file',
        'file1.txt',
        'file1.txt.txt',
        'file2.txt',
        'file3.txt',
    ]);
    $selectedFiles = iterator_to_array($fileSelection->selectedFiles());

    assertSameArray($testFiles, $selectedFiles);
});

it('can exclude files with wildcards from a given subdirectory', function () {
    $fileSelection = (new FileSelection($this->sourceDirectory))
        ->excludeFilesFrom(getTestFiles([
            '*/file1.txt',
            '*/directory1',
        ]));

    $testFiles = getTestFiles([
        '.dot',
        '.dotfile',
        '1Mb.file',
        'directory1',
        'directory1/file2.txt',
        'directory2',
        'file',
        'file1.txt', //it is kept because it is not in a directory /dir/file1.txt
        'file1.txt.txt',
        'file2.txt',
        'file3.txt',
    ]);
    $selectedFiles = iterator_to_array($fileSelection->selectedFiles());

    assertSameArray($testFiles, $selectedFiles);
});

it('can select files from multiple directories', function () {
    $fileSelection = (new FileSelection([
        $this->sourceDirectory.'/directory1/directory1',
        $this->sourceDirectory.'/directory2/directory1',
    ]));

    assertSameArrayContent(
        getTestFiles([
            'directory1/directory1/file2.txt',
            'directory1/directory1/file1.txt',
            'directory2/directory1/file1.txt',
        ]),
        iterator_to_array($fileSelection->selectedFiles())
    );
});

it('can exclude files from multiple directories', function () {
    $fileSelection = (new FileSelection($this->sourceDirectory))
        ->excludeFilesFrom(getTestFiles([
            'directory1/directory1',
            'directory2',
            'file2.txt',
        ]));

    $testFiles = getTestFiles([
        '.dot',
        '.dot/file1.txt',
        '.dotfile',
        '1Mb.file',
        'directory1',
        'directory1/file1.txt',
        'directory1/file2.txt',
        'file',
        'file1.txt',
        'file1.txt.txt',
        'file3.txt',
    ]);
    $selectedFiles = iterator_to_array($fileSelection->selectedFiles());

    assertSameArray($testFiles, $selectedFiles);
});

it('returns an empty array when not specifying any directories', function () {
    $fileSelection = new FileSelection;

    expect(iterator_to_array($fileSelection->selectedFiles()))->toBeEmpty();
});

it('returns an empty array if everything is excluded', function () {
    $fileSelection = (new FileSelection($this->sourceDirectory))
        ->excludeFilesFrom($this->sourceDirectory);

    expect(iterator_to_array($fileSelection->selectedFiles()))->toBeEmpty();
});

it('can select a single file', function () {
    $fileSelection = (new FileSelection([
        $this->sourceDirectory.'/.dotfile',
    ]));

    $this->assertSame(
        getTestFiles([
            '.dotfile',
        ]),
        iterator_to_array($fileSelection->selectedFiles())
    );
});

it('provides a factory method', function () {
    $fileSelection = FileSelection::create();

    expect($fileSelection)->toBeInstanceOf(FileSelection::class);
});

function assertSameArrayContent($expected, $actual, $message = '')
{
    test()->assertCount(count($expected), array_intersect($expected, $actual), $message);
}

function getTestFiles(array $relativePaths): array
{
    $testDirectory = test()->getStubDirectory();

    return array_map(fn ($path) => "{$testDirectory}/{$path}", $relativePaths);
}

function assertSameArray(array $array1, array $array2)
{
    sort($array1);
    sort($array2);
    expect($array2)->toBe($array1);
}
