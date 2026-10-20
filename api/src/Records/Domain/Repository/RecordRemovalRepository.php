<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Domain\Repository;

use App\Records\Domain\Model\RecordRemoval;
use Symfony\Component\Uid\Uuid;

interface RecordRemovalRepository
{
    public function save(RecordRemoval $removal): void;

    public function ofId(Uuid $companyId, Uuid $id): ?RecordRemoval;

    /** The removal, its row locked until the transaction ends, so that a restore and its end never both run. */
    public function lockedOfId(Uuid $companyId, Uuid $id): ?RecordRemoval;

    /**
     * The removals of these subjects still waiting, the newest first.
     *
     * @param list<string> $subjects
     *
     * @return list<RecordRemoval>
     */
    public function pendingOf(Uuid $companyId, array $subjects): array;

    /**
     * The ids of the removals whose day ended, of every company or of one.
     *
     * @return list<array{company: Uuid, id: Uuid}>
     */
    public function due(\DateTimeImmutable $now, ?Uuid $companyId = null): array;
}
