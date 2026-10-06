<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetRepository;
use App\Identity\Domain\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

final readonly class DoctrinePasswordResetRepository implements PasswordResetRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofTokenHash(string $tokenHash): ?PasswordReset
    {
        return $this->entityManager->getRepository(PasswordReset::class)->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function lockedOfTokenHash(string $tokenHash): ?PasswordReset
    {
        $reset = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PasswordReset::class, 'r')
            ->where('r.tokenHash = :hash')
            ->setParameter('hash', $tokenHash)
            ->getQuery()
            // SELECT … FOR UPDATE inside the transaction; the refresh hint replaces what the first read left in memory.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $reset instanceof PasswordReset ? $reset : null;
    }

    public function openFor(User $user): array
    {
        return $this->entityManager->getRepository(PasswordReset::class)->findBy(['user' => $user, 'usedAt' => null]);
    }

    public function save(PasswordReset $reset): void
    {
        $this->entityManager->persist($reset);
        $this->entityManager->flush();
    }

    public function remove(PasswordReset $reset): void
    {
        $this->entityManager->remove($reset);
        $this->entityManager->flush();
    }
}
