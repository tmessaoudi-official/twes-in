<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

/**
 * Doctrine's optimistic lock: the entity's #[ORM\Version] compared with the version the editor opened. The flush then
 * writes `WHERE version = …`, which catches a save landing between this check and the write (DoctrineTransactions).
 */
final readonly class DoctrineRecordVersions implements RecordVersions
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function expect(object $record, int $version): void
    {
        // Doctrine says an unversioned entity with the same exception; that is a mapping mistake, never a 409.
        if (!$this->entityManager->getClassMetadata($record::class)->isVersioned) {
            throw new \LogicException(\sprintf('%s carries no #[ORM\Version] to compare.', $record::class));
        }
        try {
            $this->entityManager->lock($record, LockMode::OPTIMISTIC, $version);
        } catch (OptimisticLockException $changed) {
            throw new RecordChanged($changed);
        }
    }
}
