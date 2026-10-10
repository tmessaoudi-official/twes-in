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
    /** @return list<Attachment> one subject's attachments, oldest first, those taken off left out */
    public function ofEntity(Uuid $companyId, string $entityType, Uuid $entityId): array;

    /** @return list<Attachment> one subject's attachments, oldest first, those taken off included */
    public function ofEntityWithRemoved(Uuid $companyId, string $entityType, Uuid $entityId): array;

    /**
     * How many attachments each of several subjects holds, those taken off left out, in one read: a list page counts its rows' at once.
     *
     * @param list<Uuid> $entityIds
     *
     * @return array<string, int> by subject id (RFC 4122), every id asked for present, 0 when it holds none
     */
    public function countsOfEntities(Uuid $companyId, string $entityType, array $entityIds): array;

    /**
     * The stored file of the oldest attachment still attached to each of several subjects, in one read and across companies: a
     * switcher names the logo of every company a person belongs to at once.
     *
     * @param list<Uuid> $entityIds
     *
     * @return array<string, string> the file id (RFC 4122) by subject id (RFC 4122), only for those that hold one
     */
    public function fileIdsOfEntities(string $entityType, array $entityIds): array;

    public function save(Attachment $attachment): void;

    public function remove(Attachment $attachment): void;
}
