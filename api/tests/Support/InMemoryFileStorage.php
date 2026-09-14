<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Files\Application\FileStorage;
use App\Files\Application\StoredFileMissing;

final class InMemoryFileStorage implements FileStorage
{
    /** @var array<string, string> the bytes by key */
    public array $contents = [];

    public function write(string $key, string $contents): void
    {
        if (\array_key_exists($key, $this->contents)) {
            throw new \LogicException(\sprintf('%s already holds a file.', $key));
        }
        $this->contents[$key] = $contents;
    }

    public function read(string $key): string
    {
        return $this->contents[$key] ?? throw new StoredFileMissing(\sprintf('No file is stored under %s.', $key));
    }
}
