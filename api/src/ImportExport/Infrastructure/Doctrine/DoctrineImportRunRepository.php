<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Doctrine;

use App\ImportExport\Domain\ImportRun;
use App\ImportExport\Domain\ImportRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineImportRunRepository implements ImportRunRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function lastOf(Uuid $companyId, string $subject, string $contentHash): ?ImportRun
    {
        /** @var ImportRun|null $run */
        $run = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ImportRun::class, 'r')
            ->where('r.company = :company')
            ->andWhere('r.subject = :subject')
            ->andWhere('r.contentHash = :hash')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('subject', $subject)
            ->setParameter('hash', $contentHash)
            ->orderBy('r.at', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $run;
    }

    public function save(ImportRun $run): void
    {
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        // Nothing reads it back in the request that wrote it, and an import's unit of work is kept small on purpose.
        $this->entityManager->detach($run);
    }
}
