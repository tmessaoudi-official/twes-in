<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Passkey;
use App\Identity\Domain\PasskeyRepository;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrinePasskeyRepository implements PasskeyRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofUser(User $user): array
    {
        // The id breaks ties: two passkeys registered within the same second share a created_at.
        return $this->entityManager->getRepository(Passkey::class)->findBy(['user' => $user], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function ofUserAndId(User $user, Uuid $id): ?Passkey
    {
        return $this->entityManager->getRepository(Passkey::class)->findOneBy(['user' => $user, 'id' => $id]);
    }

    public function ofUserAndCredentialId(User $user, string $credentialId): ?Passkey
    {
        return $this->entityManager->getRepository(Passkey::class)->findOneBy(['user' => $user, 'credentialId' => $credentialId]);
    }

    public function existsWithCredentialId(string $credentialId): bool
    {
        return null !== $this->entityManager->getRepository(Passkey::class)->findOneBy(['credentialId' => $credentialId]);
    }

    public function countFor(User $user): int
    {
        return $this->entityManager->getRepository(Passkey::class)->count(['user' => $user]);
    }

    public function save(Passkey $passkey): void
    {
        $this->entityManager->persist($passkey);
        $this->entityManager->flush();
    }

    public function remove(Passkey $passkey): void
    {
        $this->entityManager->remove($passkey);
        $this->entityManager->flush();
    }
}
