<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Doctrine;

use App\Inbox\Domain\NotificationPreference;
use App\Inbox\Domain\NotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineNotificationPreferenceRepository implements NotificationPreferenceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofUser(Uuid $userId): array
    {
        return $this->entityManager->getRepository(NotificationPreference::class)->findBy(['user' => $userId]);
    }

    public function find(Uuid $userId, ?Uuid $companyId, string $type): ?NotificationPreference
    {
        return $this->entityManager->getRepository(NotificationPreference::class)->findOneBy(['user' => $userId, 'company' => $companyId, 'type' => $type]);
    }

    public function save(NotificationPreference $preference): void
    {
        $this->entityManager->persist($preference);
        $this->entityManager->flush();
    }
}
