<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\Doctrine;

use App\Files\Domain\StoredFile;
use App\Files\Domain\StoredFileRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineStoredFileRepository implements StoredFileRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(StoredFile $file): void
    {
        $this->entityManager->persist($file);
        $this->entityManager->flush();
    }
}
