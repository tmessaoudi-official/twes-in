<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Domain;

use Symfony\Component\Uid\Uuid;

interface StoredFileRepository
{
    public function save(StoredFile $file): void;

    /** How many bytes a company keeps, every file it was ever given counted: a stored file is never replaced. */
    public function bytesOfCompany(Uuid $companyId): int;
}
