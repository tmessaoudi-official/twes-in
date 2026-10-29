<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Doctrine;

use App\Demo\Domain\Model\DemoMail;
use App\Demo\Domain\Repository\DemoMailRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineDemoMailRepository implements DemoMailRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(DemoMail $mail): void
    {
        $this->entityManager->persist($mail);
        $this->entityManager->flush();
    }

    public function ofSandbox(Uuid $sandboxId): array
    {
        /** @var list<DemoMail> $mails */
        $mails = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(DemoMail::class, 'm')
            ->where('IDENTITY(m.sandbox) = :sandbox')
            ->setParameter('sandbox', $sandboxId, 'uuid')
            ->orderBy('m.capturedAt', \SortDirection::Descending)
            ->addOrderBy('m.id', \SortDirection::Descending)
            ->getQuery()
            ->getResult();

        return $mails;
    }
}
