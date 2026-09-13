<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fiscal\Domain\Unit;
use App\Fiscal\Domain\UnitRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryUnits implements UnitRepository
{
    /** @var list<Unit> */
    public array $units = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->units, static fn (Unit $u) => $u->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (Unit $a, Unit $b) => [$a->getSortOrder(), $a->getCode()] <=> [$b->getSortOrder(), $b->getCode()]);

        return $mine;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Unit
    {
        foreach ($this->ofCompany($companyId) as $unit) {
            if ($unit->getId()->equals($id)) {
                return $unit;
            }
        }

        return null;
    }

    public function ofCodeInCompany(string $code, Uuid $companyId): ?Unit
    {
        foreach ($this->ofCompany($companyId) as $unit) {
            if ($unit->getCode() === $code) {
                return $unit;
            }
        }

        return null;
    }

    public function save(Unit $unit): void
    {
        if (!\in_array($unit, $this->units, true)) {
            $this->units[] = $unit;
        }
    }
}
