<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\Doctrine;

use App\Files\Domain\Attachment;
use App\Files\Domain\AttachmentRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAttachmentRepository implements AttachmentRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofEntity(Uuid $companyId, string $entityType, Uuid $entityId): array
    {
        /** @var list<Attachment> $attachments */
        $attachments = $this->entityManager->getRepository(Attachment::class)->findBy(
            ['company' => $companyId, 'entityType' => $entityType, 'entityId' => $entityId],
            ['createdAt' => 'ASC', 'id' => 'ASC'],
        );

        return $attachments;
    }

    public function countsOfEntities(Uuid $companyId, string $entityType, array $entityIds): array
    {
        $counts = array_fill_keys(array_map(static fn (Uuid $id): string => $id->toRfc4122(), $entityIds), 0);
        if ([] === $counts) {
            return [];
        }
        /** @var list<array{entityId: Uuid, attached: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('a.entityId', 'COUNT(a.id) AS attached')->from(Attachment::class, 'a')
            ->where('a.company = :company')->andWhere('a.entityType = :type')->andWhere('a.entityId IN (:ids)')
            ->groupBy('a.entityId')
            ->setParameter('company', $companyId, 'uuid')->setParameter('type', $entityType)
            ->setParameter('ids', array_keys($counts), ArrayParameterType::STRING)
            ->getQuery()->getResult();
        foreach ($rows as $row) {
            $counts[$row['entityId']->toRfc4122()] = (int) $row['attached'];
        }

        return $counts;
    }

    public function save(Attachment $attachment): void
    {
        $this->entityManager->persist($attachment);
        $this->entityManager->flush();
    }

    public function remove(Attachment $attachment): void
    {
        $this->entityManager->remove($attachment);
        $this->entityManager->flush();
    }
}
