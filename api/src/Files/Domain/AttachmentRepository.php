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

    /**
     * How many attachments each of several subjects holds, in one read: a list page counts its rows' at once.
     *
     * @param list<Uuid> $entityIds
     *
     * @return array<string, int> by subject id (RFC 4122), every id asked for present, 0 when it holds none
     */
    public function countsOfEntities(Uuid $companyId, string $entityType, array $entityIds): array;

    public function save(Attachment $attachment): void;

    public function remove(Attachment $attachment): void;
}
