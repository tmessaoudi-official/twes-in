<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\EstablishmentRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryEstablishments implements EstablishmentRepository
{
    /** @var list<Establishment> */
    public array $establishments = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->establishments, static fn (Establishment $e) => $e->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (Establishment $a, Establishment $b) => [!$a->isDefault(), $a->getCode()] <=> [!$b->isDefault(), $b->getCode()]);

        return $mine;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Establishment
    {
        foreach ($this->ofCompany($companyId) as $establishment) {
            if ($establishment->getId()->equals($id)) {
                return $establishment;
            }
        }

        return null;
    }

    public function ofCodeInCompany(string $code, Uuid $companyId): ?Establishment
    {
        foreach ($this->ofCompany($companyId) as $establishment) {
            if ($establishment->getCode() === $code) {
                return $establishment;
            }
        }

        return null;
    }

    public function save(Establishment $establishment): void
    {
        if (!\in_array($establishment, $this->establishments, true)) {
            $this->establishments[] = $establishment;
        }
    }
}
