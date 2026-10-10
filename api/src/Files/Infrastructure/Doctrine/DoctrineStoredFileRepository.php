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
use Symfony\Component\Uid\Uuid;

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

    public function ofIdsInCompany(array $ids, Uuid $companyId): array
    {
        if ([] === $ids) {
            return [];
        }
        /** @var list<StoredFile> $files */
        $files = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(StoredFile::class, 'f')
            ->where('f.company = :company')
            ->andWhere('f.id IN (:ids)')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids))
            ->getQuery()
            ->getResult();

        return $files;
    }

    public function remove(StoredFile $file): void
    {
        $this->entityManager->remove($file);
        $this->entityManager->flush();
    }

    public function bytesOfCompany(Uuid $companyId): int
    {
        $bytes = $this->entityManager->getConnection()->fetchOne('SELECT COALESCE(SUM(size), 0) FROM file WHERE company_id = ?', [$companyId->toRfc4122()]);

        return is_numeric($bytes) ? (int) $bytes : 0;
    }
}
