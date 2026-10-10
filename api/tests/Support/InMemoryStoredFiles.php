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

    public function ofIdsInCompany(array $ids, Uuid $companyId): array
    {
        return array_values(array_filter($this->files, static fn (StoredFile $file): bool => $file->getCompany()->getId()->equals($companyId) && \in_array($file->getId()->toRfc4122(), array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids), true)));
    }

    public function remove(StoredFile $file): void
    {
        $this->files = array_values(array_filter($this->files, static fn (StoredFile $kept): bool => $kept !== $file));
    }

    public function bytesOfCompany(Uuid $companyId): int
    {
        return array_sum(array_map(static fn (StoredFile $file): int => $file->getCompany()->getId()->equals($companyId) ? $file->getSize() : 0, $this->files));
    }
}
