<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain;

use Symfony\Component\Uid\Uuid;

interface UnitRepository
{
    /** @return list<Unit> one company's units, by sort order then code */
    public function ofCompany(Uuid $companyId): array;

    /** Null for a unit that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Unit;

    public function ofCodeInCompany(string $code, Uuid $companyId): ?Unit;

    public function save(Unit $unit): void;
}
