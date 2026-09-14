<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\Flysystem;

use App\Files\Application\FileStorage;
use App\Files\Application\StoredFileMissing;
use League\Flysystem\FilesystemOperator;
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
}
