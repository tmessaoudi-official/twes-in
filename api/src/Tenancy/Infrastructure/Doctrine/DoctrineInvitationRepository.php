<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use App\Tenancy\Domain\Invitation;
use App\Tenancy\Domain\InvitationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineInvitationRepository implements InvitationRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofTokenHash(string $tokenHash): ?Invitation
    {
        return $this->entityManager->getRepository(Invitation::class)->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function pendingFor(Uuid $companyId, string $email): ?Invitation
    {
        return $this->entityManager->getRepository(Invitation::class)
            ->findOneBy(['company' => $companyId, 'email' => $email, 'acceptedAt' => null]);
    }

    public function openOfCompany(Uuid $companyId, \DateTimeImmutable $now): array
    {
        /** @var list<Invitation> $open */
        $open = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invitation::class, 'i')
            ->where('i.company = :company')
            ->andWhere('i.acceptedAt IS NULL')
            ->andWhere('i.expiresAt >= :now')
            ->orderBy('i.createdAt', 'ASC')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getResult();

        return $open;
    }

    public function save(Invitation $invitation): void
    {
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();
    }

    public function remove(Invitation $invitation): void
    {
        $this->entityManager->remove($invitation);
        $this->entityManager->flush();
    }
}
