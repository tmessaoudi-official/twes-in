<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Scanning\Infrastructure\Doctrine;

use App\Scanning\Domain\ScanPairing;
use App\Scanning\Domain\ScanPairingRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineScanPairingRepository implements ScanPairingRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(ScanPairing ...$pairings): void
    {
        foreach ($pairings as $pairing) {
            $this->entityManager->persist($pairing);
        }
        $this->entityManager->flush();
    }

    public function get(Uuid $id): ?ScanPairing
    {
        return $this->entityManager->find(ScanPairing::class, $id);
    }

    public function ofUser(Uuid $userId, Uuid $id): ?ScanPairing
    {
        $pairing = $this->get($id);

        return null !== $pairing && $pairing->getUser()->getId()->equals($userId) ? $pairing : null;
    }

    public function lockedByLinkHash(string $linkHash): ?ScanPairing
    {
        /** @var ScanPairing|null $pairing */
        $pairing = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(ScanPairing::class, 'p')
            ->where('p.linkHash = :linkHash')
            ->setParameter('linkHash', $linkHash)
            ->getQuery()
            // SELECT … FOR UPDATE: the second of two phones opening one link waits here, then reads it claimed.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $pairing;
    }

    public function unendedOf(Uuid $userId): array
    {
        /** @var list<ScanPairing> $pairings */
        $pairings = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(ScanPairing::class, 'p')
            ->where('IDENTITY(p.user) = :user')
            ->andWhere('p.endedAt IS NULL')
            ->setParameter('user', $userId, 'uuid')
            ->getQuery()
            ->getResult();

        return $pairings;
    }
}
