<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Files\Domain\StoredFile;
use App\Files\Domain\StoredFileRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryStoredFiles implements StoredFileRepository
{
    /** @var list<StoredFile> */
    public array $files = [];

    public function save(StoredFile $file): void
    {
        if (!\in_array($file, $this->files, true)) {
            $this->files[] = $file;
        }
    }

    public function bytesOfCompany(Uuid $companyId): int
    {
        return array_sum(array_map(static fn (StoredFile $file): int => $file->getCompany()->getId()->equals($companyId) ? $file->getSize() : 0, $this->files));
    }
}
