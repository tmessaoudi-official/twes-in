<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Domain;

use Symfony\Component\Uid\Uuid;

interface AttachmentRepository
{
    /** @return list<Attachment> one subject's attachments, oldest first */
    public function ofEntity(Uuid $companyId, string $entityType, Uuid $entityId): array;

    public function save(Attachment $attachment): void;

    public function remove(Attachment $attachment): void;
}
