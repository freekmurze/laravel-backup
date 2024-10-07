<?php

namespace Spatie\Backup\Tasks\Backup;

use Illuminate\Support\Str;
use Spatie\Backup\Helpers\Format;
use ZipArchive;

class Zip
{
    protected ZipArchive $zipFile;

    protected int $fileCount = 0;

    protected string $pathToZip;

    protected ?Encryption $encryption = null;

    public static function createForManifest(Manifest $manifest, string $pathToZip): self
    {
        $relativePath = config('backup.backup.source.files.relative_path') ?
            rtrim(config('backup.backup.source.files.relative_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : false;

        $zip = new static($pathToZip);

        $zip->open();

        foreach ($manifest->files() as $file) {
            $zip->add($file, self::determineNameOfFileInZip($file, $pathToZip, $relativePath));
        }

        $zip->encrypt();

        $zip->close();

        return $zip;
    }

    protected static function determineNameOfFileInZip(string $pathToFile, string $pathToZip, string $relativePath)
    {
        $fileDirectory = pathinfo($pathToFile, PATHINFO_DIRNAME).DIRECTORY_SEPARATOR;

        $zipDirectory = pathinfo($pathToZip, PATHINFO_DIRNAME).DIRECTORY_SEPARATOR;

        if (Str::startsWith($fileDirectory, $zipDirectory)) {
            return substr($pathToFile, strlen($zipDirectory));
        }

        if ($relativePath && $relativePath != DIRECTORY_SEPARATOR && Str::startsWith($fileDirectory, $relativePath)) {
            return substr($pathToFile, strlen($relativePath));
        }

        return $pathToFile;
    }

    public function __construct(string $pathToZip)
    {
        $this->zipFile = new ZipArchive;

        $this->pathToZip = $pathToZip;

        $this->open();
    }

    public function path(): string
    {
        return $this->pathToZip;
    }

    public function size(): float
    {
        if ($this->fileCount === 0) {
            return 0;
        }

        return filesize($this->pathToZip);
    }

    public function humanReadableSize(): string
    {
        return Format::humanReadableSize($this->size());
    }

    public function open(): void
    {
        $this->zipFile->open($this->pathToZip, ZipArchive::CREATE);
    }

    public function close(): void
    {
        $this->zipFile->close();
    }

    public function setPassword(string $password): void
    {
        $this->zipFile->setPassword($password);
    }

    public function add(string|iterable $files, ?string $nameInZip = null): self
    {
        if (is_array($files)) {
            $nameInZip = null;
        }

        if (is_string($files)) {
            $files = [$files];
        }

        $compressionMethod = config('backup.backup.destination.compression_method', null);
        $compressionLevel = config('backup.backup.destination.compression_level', 9);

        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->zipFile->addEmptyDir(ltrim($nameInZip ?: $file, DIRECTORY_SEPARATOR));
            }

            if (is_file($file)) {
                $this->zipFile->addFile($file, ltrim($nameInZip, DIRECTORY_SEPARATOR));

                if (is_int($compressionMethod)) {
                    $this->zipFile->setCompressionName(
                        ltrim($nameInZip ?: $file, DIRECTORY_SEPARATOR),
                        $compressionMethod,
                        $compressionLevel
                    );
                }
            }
            $this->fileCount++;
        }

        return $this;
    }

    public function count(): int
    {
        return $this->fileCount;
    }

    public function encrypt()
    {
        $this->loadEncryption();

        if ($this->getEncryption()) {
            $this->setPassword($this->getEncryption()->getPassword());

            foreach (range(0, $this->zipFile->numFiles - 1) as $i) {
                $this->zipFile->setEncryptionIndex($i, $this->getEncryption()->getMethod());
            }
        }
    }

    public function getEncryption(): ?Encryption
    {
        return $this->encryption;
    }

    public function loadEncryption()
    {
        $password = config('backup.backup.password');
        $method = config('backup.backup.encryption');

        if ($method === 'default') {
            $method = defined("\ZipArchive::EM_AES_256")
                ? ZipArchive::EM_AES_256
                : null;
        }

        if ($password === null) {
            return false;
        }

        if (! is_int($method)) {
            return false;
        }

        $this->encryption = new Encryption($password, $method);
    }
}
