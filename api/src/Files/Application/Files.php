<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Application;

use App\Files\Domain\StoredFile;
use App\Files\Domain\StoredFileRepository;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Keeps a company's files and gives their bytes back only while they are still the ones recorded. */
final readonly class Files
{
    public function __construct(private FileStorage $storage, private StoredFileRepository $records, private ClockInterface $clock)
    {
    }

    public function store(Company $company, string $originalName, string $mime, string $contents, ?Uuid $uploadedBy): StoredFile
    {
        $file = new StoredFile($company, $originalName, $mime, $contents, $uploadedBy, $this->clock->now());
        // The bytes first: bytes without a record are only unused, a record without its bytes would be served as missing.
        $this->storage->write($file->getStorageKey(), $contents);
        $this->records->save($file);

        return $file;
    }

    /**
     * @throws StoredFileMissing
     * @throws StoredFileCorrupted
     */
    public function contents(StoredFile $file): string
    {
        $contents = $this->storage->read($file->getStorageKey());
        if (!$file->matches($contents)) {
            throw new StoredFileCorrupted(\sprintf('The file %s no longer matches the bytes recorded for it.', $file->getId()->toRfc4122()));
        }

        return $contents;
    }
}
