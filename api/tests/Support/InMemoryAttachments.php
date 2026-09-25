<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Files\Domain\Attachment;
use App\Files\Domain\AttachmentRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryAttachments implements AttachmentRepository
{
    /** @var list<Attachment> */
    public array $attachments = [];

    public function ofEntity(Uuid $companyId, string $entityType, Uuid $entityId): array
    {
        return array_values(array_filter(
            $this->attachments,
            static fn (Attachment $a) => $a->getCompany()->getId()->equals($companyId) && $a->getEntityType() === $entityType && $a->getEntityId()->equals($entityId),
        ));
    }

    public function countsOfEntities(Uuid $companyId, string $entityType, array $entityIds): array
    {
        $counts = [];
        foreach ($entityIds as $entityId) {
            $counts[$entityId->toRfc4122()] = \count($this->ofEntity($companyId, $entityType, $entityId));
        }

        return $counts;
    }

    public function save(Attachment $attachment): void
    {
        if (!\in_array($attachment, $this->attachments, true)) {
            $this->attachments[] = $attachment;
        }
    }

    public function remove(Attachment $attachment): void
    {
        $this->attachments = array_values(array_filter($this->attachments, static fn (Attachment $a) => $a !== $attachment));
    }
}
