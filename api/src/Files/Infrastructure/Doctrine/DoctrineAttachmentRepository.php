<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\Doctrine;

use App\Files\Domain\Attachment;
use App\Files\Domain\AttachmentRepository;
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
