<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Domain;

use Symfony\Component\Uid\Uuid;

interface DataErasureRepository
{
    public function save(DataErasure $erasure): void;

    public function pendingOf(Uuid $companyId): ?DataErasure;

    /** The company's erasure waiting for its end, its end come or not, held until the transaction ends. */
    public function lockedPendingOf(Uuid $companyId): ?DataErasure;

    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?DataErasure;

    /**
     * Every company's erasures whose end has come and that nothing ended yet.
     *
     * @return list<Uuid> their ids
     */
    public function dueAt(\DateTimeImmutable $now, ?Uuid $companyId = null): array;

    /** Locked, whatever its company: the worker ends every company's erasures. */
    public function lockedOfId(Uuid $id): ?DataErasure;
}
