<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\User;
use App\Identity\Domain\UserSession;
use App\Identity\Domain\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineUserSessionRepository implements UserSessionRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofSessionId(string $sessionId): ?UserSession
    {
        return $this->entityManager->getRepository(UserSession::class)->findOneBy(['sessionHash' => UserSession::hashOf($sessionId)]);
    }

    public function ofId(Uuid $id): ?UserSession
    {
        return $this->entityManager->find(UserSession::class, $id);
    }

    public function seenSince(User $user, \DateTimeImmutable $since): array
    {
        // One account holds a handful of sessions, since the ones past the absolute limit are forgotten as new ones arrive.
        $sessions = $this->entityManager->getRepository(UserSession::class)->findBy(['user' => $user], ['lastSeenAt' => 'DESC', 'id' => 'ASC']);

        return array_values(array_filter($sessions, static fn (UserSession $session): bool => $session->getLastSeenAt() > $since));
    }

    public function removeOlderThan(User $user, \DateTimeImmutable $before): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(UserSession::class, 's')
            ->where('s.user = :user')->andWhere('s.lastSeenAt < :before')
            ->setParameter('user', $user->getId(), 'uuid')->setParameter('before', $before)
            ->getQuery()->execute();
    }

    public function save(UserSession $session): void
    {
        $this->entityManager->persist($session);
        $this->entityManager->flush();
    }
}
