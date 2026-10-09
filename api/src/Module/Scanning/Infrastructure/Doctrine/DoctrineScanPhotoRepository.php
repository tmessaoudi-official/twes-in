<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Scanning\Infrastructure\Doctrine;

use App\Module\Scanning\Domain\ScanPhoto;
use App\Module\Scanning\Domain\ScanPhotoRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineScanPhotoRepository implements ScanPhotoRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function saveWhileFewerThan(ScanPhoto $photo, int $held, \DateTimeImmutable $since): bool
    {
        return $this->entityManager->wrapInTransaction(function () use ($photo, $held, $since): bool {
            // SELECT … FOR UPDATE on the pairing: a second photo sent at the same moment waits here, then counts this one.
            $this->entityManager->lock($photo->getPairing(), LockMode::PESSIMISTIC_WRITE);
            $waiting = (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(p.id)')
                ->from(ScanPhoto::class, 'p')
                ->where('IDENTITY(p.pairing) = :pairing')
                ->andWhere('p.createdAt > :since')
                ->setParameter('pairing', $photo->getPairing()->getId(), 'uuid')
                ->setParameter('since', $since, 'datetime_immutable')
                ->getQuery()
                ->getSingleScalarResult();
            if ($waiting >= $held) {
                return false;
            }
            $this->entityManager->persist($photo);
            $this->entityManager->flush();

            return true;
        });
    }

    public function ofPairing(Uuid $pairingId, Uuid $photoId): ?ScanPhoto
    {
        $photo = $this->entityManager->find(ScanPhoto::class, $photoId);

        return null !== $photo && $photo->getPairing()->getId()->equals($pairingId) ? $photo : null;
    }

    public function remove(ScanPhoto $photo): void
    {
        $this->entityManager->remove($photo);
        $this->entityManager->flush();
    }

    public function removeSentBefore(\DateTimeImmutable $moment): int
    {
        $cleared = $this->entityManager->createQueryBuilder()
            ->delete(ScanPhoto::class, 'p')
            ->where('p.createdAt < :moment')
            ->setParameter('moment', $moment, 'datetime_immutable')
            ->getQuery()
            ->execute();

        return \is_int($cleared) ? $cleared : 0;
    }
}
