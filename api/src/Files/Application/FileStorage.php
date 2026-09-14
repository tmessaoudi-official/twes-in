<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Application;

/** Where a file's bytes live (docs/SPEC.md § 2 Files: Flysystem on a local volume, S3-compatible storage by configuration). */
interface FileStorage
{
    /** @throws \LogicException when the key already holds a file: a stored file is never replaced */
    public function write(string $key, string $contents): void;

    /** @throws StoredFileMissing */
    public function read(string $key): string;
}
