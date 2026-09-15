<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofId(Uuid $id): ?User
    {
        return $this->entityManager->find(User::class, $id);
    }

    public function ofEmail(Email $email): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    public function search(string $text, int $limit): array
    {
        /** @var list<User> $users */
        $users = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('LOWER(u.email) LIKE :text OR LOWER(u.displayName) LIKE :text')
            ->setParameter('text', '%'.addcslashes(mb_strtolower(trim($text)), '%_\\').'%')
            ->orderBy('u.email', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $users;
    }

    public function save(User $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}
