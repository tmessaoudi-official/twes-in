<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Files\Domain\StoredFile;
use App\Files\Domain\StoredFileRepository;

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
}
