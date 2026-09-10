<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineMembershipRepository implements MembershipRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function anyCompanyRequiresMfa(Uuid $userId): bool
    {
        // One query, no limit: this decides whether an account is allowed to work at all, so it must not be
        // answerable by a page of results that happened to stop early.
        return (bool) $this->entityManager->createQuery(
            'SELECT COUNT(m.id) FROM '.Membership::class.' m JOIN m.company c WHERE m.user = :user AND c.mfaRequired = true',
        )->setParameter('user', $userId, 'uuid')->getSingleScalarResult();
    }

    public function ofUser(Uuid $userId, int $limit): array
    {
        return $this->entityManager->getRepository(Membership::class)->findBy(['user' => $userId], limit: $limit);
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(Membership::class)->findBy(['company' => $companyId], ['createdAt' => 'ASC']);
    }

    public function ofUserInCompany(Uuid $userId, Uuid $companyId): ?Membership
    {
        return $this->entityManager->getRepository(Membership::class)->findOneBy(['user' => $userId, 'company' => $companyId]);
    }

    public function save(Membership $membership): void
    {
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
    }

    public function remove(Membership $membership): void
    {
        $this->entityManager->remove($membership);
        $this->entityManager->flush();
    }
}
