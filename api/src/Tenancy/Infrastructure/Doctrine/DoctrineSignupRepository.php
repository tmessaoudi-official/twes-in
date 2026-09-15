<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use App\Identity\Domain\Email;
use App\Tenancy\Domain\Signup;
use App\Tenancy\Domain\SignupRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSignupRepository implements SignupRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofTokenHash(string $tokenHash): ?Signup
    {
        return $this->entityManager->getRepository(Signup::class)->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function openFor(Email $email): ?Signup
    {
        return $this->entityManager->getRepository(Signup::class)->findOneBy(['email' => $email->value, 'completedAt' => null]);
    }

    public function save(Signup $signup): void
    {
        $this->entityManager->persist($signup);
        $this->entityManager->flush();
    }

    public function remove(Signup $signup): void
    {
        $this->entityManager->remove($signup);
        $this->entityManager->flush();
    }
}
