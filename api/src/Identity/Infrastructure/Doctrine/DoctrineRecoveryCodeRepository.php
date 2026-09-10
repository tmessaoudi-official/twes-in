<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\RecoveryCodeEntry;
use App\Identity\Domain\RecoveryCodeRepository;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRecoveryCodeRepository implements RecoveryCodeRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function unspent(User $user, string $codeHash): ?RecoveryCodeEntry
    {
        return $this->entityManager->getRepository(RecoveryCodeEntry::class)
            ->findOneBy(['user' => $user, 'codeHash' => $codeHash]);
    }

    public function ofUser(User $user): array
    {
        return $this->entityManager->getRepository(RecoveryCodeEntry::class)->findBy(['user' => $user]);
    }

    public function replaceAll(User $user, array $entries): void
    {
        foreach ($this->ofUser($user) as $existing) {
            $this->entityManager->remove($existing);
        }

        foreach ($entries as $entry) {
            $this->entityManager->persist($entry);
        }

        $this->entityManager->flush();
    }

    public function spend(RecoveryCodeEntry $entry): void
    {
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
    }

    public function countFor(User $user): int
    {
        return \count($this->ofUser($user));
    }
}
