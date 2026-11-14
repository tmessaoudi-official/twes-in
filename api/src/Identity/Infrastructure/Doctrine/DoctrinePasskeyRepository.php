<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Model\Passkey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\PasskeyRepository;
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
        return null !== $this->ofCredentialId($credentialId);
    }

    public function ofCredentialId(string $credentialId): ?Passkey
    {
        return $this->entityManager->getRepository(Passkey::class)->findOneBy(['credentialId' => $credentialId]);
    }

    public function countFor(User $user): int
    {
        return $this->entityManager->getRepository(Passkey::class)->count(['user' => $user]);
    }

    public function countAccountsWithPasskeysAlone(): int
    {
        $count = $this->entityManager->createQuery(
            'SELECT COUNT(DISTINCT u.id) FROM '.Passkey::class.' p JOIN p.user u WHERE u.totpSecret IS NULL OR u.totpConfirmedAt IS NULL',
        )->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : throw new \UnexpectedValueException('A count reads as a number.');
    }

    public function holdersOfPasskeysNotMadeOn(string $host, string $installationName): array
    {
        /** @var list<User> $users */
        $users = $this->entityManager->createQuery(
            'SELECT u FROM '.User::class.' u WHERE u.id IN (SELECT IDENTITY(p.user) FROM '.Passkey::class.' p WHERE COALESCE(p.host, :installation) <> :host) ORDER BY u.id ASC',
        )->setParameter('host', $host)->setParameter('installation', $installationName)->getResult();

        return array_map(static fn (User $user): Uuid => $user->getId(), $users);
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
