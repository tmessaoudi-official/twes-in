<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\Flysystem;

use App\Files\Application\FileStorage;
use App\Files\Application\StoredFileMissing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemReader;
use League\Flysystem\UnableToReadFile;

/** A file's bytes on whatever Flysystem adapter the configuration names: a local volume by default. */
final readonly class FlysystemFileStorage implements FileStorage
{
    public function __construct(private FilesystemOperator $filesystem)
    {
    }

    public function write(string $key, string $contents): void
    {
        if ($this->filesystem->fileExists($key)) {
            throw new \LogicException(\sprintf('%s already holds a file, and a stored file is never replaced.', $key));
        }
        $this->filesystem->write($key, $contents);
    }

    public function read(string $key): string
    {
        try {
            return $this->filesystem->read($key);
        } catch (UnableToReadFile $absent) {
            throw new StoredFileMissing(\sprintf('No file is stored under %s.', $key), 0, $absent);
        }
    }

    public function delete(string $key): void
    {
        // Flysystem's delete of an absent file succeeds on every adapter it ships.
        $this->filesystem->delete($key);
    }

    public function keysUnder(string $prefix): iterable
    {
        foreach ($this->filesystem->listContents($prefix, FilesystemReader::LIST_DEEP) as $item) {
            if (!$item instanceof FileAttributes) {
                continue;
            }
            // Both adapters it ships for, a local volume and an S3 bucket, list a file with the moment it was written.
            $written = $item->lastModified() ?? throw new \UnexpectedValueException(\sprintf('The storage lists %s without when it was written.', $item->path()));

            yield $item->path() => new \DateTimeImmutable('@'.$written);
        }
    }
}
